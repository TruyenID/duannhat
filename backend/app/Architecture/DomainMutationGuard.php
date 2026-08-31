<?php

declare(strict_types=1);

namespace App\Architecture;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class DomainMutationGuard
{
    /** @var list<string> */
    private const MUTATORS = [
        'create', 'createMany', 'createManyQuietly', 'createQuietly', 'createOrFirst',
        'forceCreate', 'forceCreateMany', 'forceCreateManyQuietly', 'forceCreateQuietly',
        'update', 'updateOrFail', 'updateQuietly', 'save', 'saveMany', 'saveManyQuietly',
        'saveOrFail', 'saveQuietly', 'push', 'pushQuietly',
        'delete', 'deleteOrFail', 'deleteQuietly', 'forceDelete', 'forceDeleteQuietly',
        'restore', 'restoreQuietly', 'updateOrCreate', 'firstOrCreate', 'incrementOrCreate',
        'markEmailAsVerified',
        'increment', 'incrementQuietly', 'incrementEach', 'decrement', 'decrementQuietly', 'decrementEach',
        'touch', 'touchQuietly', 'destroy', 'truncate', 'insert', 'insertGetId', 'insertOrIgnore', 'insertUsing',
        'insertOrIgnoreUsing', 'updateOrInsert', 'upsert', 'attach', 'detach', 'sync',
        'syncWithoutDetaching', 'syncWithPivotValues', 'toggle', 'updateExistingPivot',
        'attachOrFail', 'detachOrFail', 'syncOrFail', 'syncWithoutDetachingOrFail',
        'syncWithPivotValuesOrFail', 'toggleOrFail', 'updateExistingPivotOrFail',
    ];

    /** @var list<string> */
    private const GENERATED_SERVICE_MUTATORS = [
        'create', 'update', 'delete', 'restore', 'forceDelete', 'emptyTrash',
    ];

    /** @var list<string> */
    private const RAW_DB_MUTATORS = [
        'insert', 'update', 'delete', 'statement', 'unprepared', 'affectingStatement',
    ];

    /** @var list<string> */
    private const QUERY_METHODS = [
        'query', 'where', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull', 'whereHas',
        'with', 'withCount', 'select', 'orderBy', 'orderByDesc', 'latest', 'oldest', 'limit',
        'lockForUpdate', 'withoutGlobalScopes', 'withTrashed', 'onlyTrashed', 'join', 'leftJoin',
        'get', 'first', 'firstOrFail', 'find', 'findOrFail', 'sole', 'cursor', 'lazy', 'pluck',
    ];

    /** @var list<string> */
    private const INSTANCE_TERMINALS = ['first', 'firstOrFail', 'find', 'findOrFail', 'sole'];

    /** @var list<string> */
    private const MODEL_COLLECTION_TERMINALS = ['all', 'cursor', 'get', 'lazy'];

    /** @var list<string> */
    private const VALUE_COLLECTION_TERMINALS = ['pluck'];

    /** @var list<string> */
    private const COLLECTION_ITEM_TERMINALS = ['find', 'first', 'firstWhere', 'get', 'last', 'pop', 'random', 'shift', 'sole'];

    /** @var list<string> */
    private const COLLECTION_HIGHER_ORDER_PROXIES = ['each', 'map'];

    /** @var list<string> */
    private const COLLECTION_PRESERVING_METHODS = ['keyBy'];

    /** @var array<string, array{models: list<string>, tables: list<string>, boundaries: list<string>}> */
    private array $aggregates;

    /** @var array<string, string> */
    private array $models = [];

    /** @var array<string, string> */
    private array $tables = [];

    /** @var array<string, array{0: string, 1: string, 2: string}> */
    private array $generatedServices = [];

    private ?string $scanRoot = null;

    /**
     * @param  array<string, array{models: list<string>, tables: list<string>, boundaries?: list<string>}>  $aggregates
     */
    public function __construct(array $aggregates)
    {
        $this->aggregates = $aggregates;

        foreach ($aggregates as $aggregate => $definition) {
            $this->aggregates[$aggregate]['boundaries'] = $definition['boundaries'] ?? [];
            foreach ($definition['models'] as $model) {
                $class = str_contains($model, '\\') ? ltrim($model, '\\') : 'App\\Models\\'.$model;
                $this->models[$class] = $aggregate;
            }
            foreach ($definition['tables'] as $table) {
                $this->tables[$table] = $aggregate;
            }
        }
    }

    /** @return list<DomainMutationFinding> */
    public function scan(string $root): array
    {
        $root = rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR);
        $this->scanRoot = $root;
        $this->generatedServices = [];
        $findings = [];
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app'));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $files[] = $file;
        }

        do {
            $discovered = false;
            foreach ($files as $file) {
                $discovered = $this->discoverGeneratedServiceSubclass($file->getPathname()) || $discovered;
            }
        } while ($discovered);

        foreach ($files as $file) {
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            foreach ($this->scanFile($file->getPathname(), $path) as $finding) {
                if (! $this->isBoundary($finding)) {
                    $findings[] = $finding;
                }
            }
        }

        usort($findings, static fn (DomainMutationFinding $left, DomainMutationFinding $right): int => [
            $left->key(), $left->line,
        ] <=> [
            $right->key(), $right->line,
        ]);

        return $findings;
    }

    /**
     * @param  list<DomainMutationFinding>  $findings
     * @param  list<array<string, mixed>>  $allowlist
     * @return array{known: list<DomainMutationFinding>, new: list<DomainMutationFinding>, stale: list<string>, errors: list<string>}
     */
    public function compare(array $findings, array $allowlist, int $gate = 2, ?string $root = null): array
    {
        if ($root !== null) {
            $this->scanRoot = rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR);
        }

        /** @var array<string, int> $allowed */
        $allowed = [];
        $errors = [];

        foreach ($allowlist as $index => $entry) {
            if (! is_array($entry)) {
                $errors[] = "Allowlist entry {$index} must be an array.";

                continue;
            }

            foreach (['aggregate', 'path', 'owner', 'removal_task', 'reason'] as $field) {
                if (! isset($entry[$field]) || ! is_string($entry[$field]) || trim($entry[$field]) === '' || trim($entry[$field]) !== $entry[$field]) {
                    $errors[] = "Allowlist entry {$index} has invalid [{$field}].";
                }
            }

            $aggregate = is_string($entry['aggregate'] ?? null) ? $entry['aggregate'] : '';
            $path = is_string($entry['path'] ?? null) ? $entry['path'] : '';
            if ($aggregate !== '' && ! isset($this->aggregates[$aggregate])) {
                $errors[] = "Allowlist entry {$index} has unknown aggregate [{$aggregate}].";
            }
            if ($path !== '' && ! $this->isCanonicalExistingPath($path)) {
                $errors[] = "Allowlist entry {$index} has nonexistent or noncanonical path [{$path}].";
            }
            if (is_string($entry['removal_task'] ?? null)
                && trim($entry['removal_task']) !== ''
                && trim($entry['removal_task']) === $entry['removal_task']
                && preg_match('/^T\d+\.\d+(?:-T\d+\.\d+)?(?:\/T\d+\.\d+(?:-T\d+\.\d+)?)*$/', $entry['removal_task']) !== 1) {
                $errors[] = "Allowlist entry {$index} has malformed removal task [{$entry['removal_task']}].";
            }
            if (is_string($entry['owner'] ?? null)
                && trim($entry['owner']) !== ''
                && trim($entry['owner']) === $entry['owner']
                && preg_match('/^plan-\d+$/', $entry['owner']) !== 1) {
                $errors[] = "Allowlist entry {$index} has malformed owner.";
            }
            if (! is_int($entry['expires_at_gate'] ?? null) || $entry['expires_at_gate'] <= $gate) {
                $errors[] = "Allowlist entry {$index} expires at or before gate {$gate}.";
            }

            $signatures = $entry['signatures'] ?? null;
            if (! is_array($signatures) || $signatures === [] || array_is_list($signatures)) {
                $errors[] = "Allowlist entry {$index} must have a non-empty signature-to-occurrence map.";

                continue;
            }

            foreach ($signatures as $signature => $occurrences) {
                if (! is_string($signature)
                    || trim($signature) !== $signature
                    || preg_match('/^[^|\s]+\|[^|\s]+\|[^|\s]+\|[a-f0-9]{16}$/', $signature) !== 1
                    || ! is_int($occurrences)
                    || $occurrences !== 1) {
                    $errors[] = "Allowlist entry {$index} has malformed writer signature or call-site occurrence count.";

                    continue;
                }

                $key = implode('|', [$aggregate, $path, $signature]);
                if (isset($allowed[$key])) {
                    $errors[] = "Duplicate allowlist entry [{$key}].";

                    continue;
                }
                $allowed[$key] = $occurrences;
            }
        }

        $known = [];
        $new = [];
        foreach ($findings as $finding) {
            $key = $finding->key();
            if ($finding->aggregate === 'unknown' || $finding->kind === 'unknown' || ($allowed[$key] ?? 0) < 1) {
                $new[] = $finding;

                continue;
            }

            $known[] = $finding;
            $allowed[$key]--;
            if ($allowed[$key] === 0) {
                unset($allowed[$key]);
            }
        }

        $stale = [];
        foreach ($allowed as $key => $count) {
            $stale[] = $key.'#missing:'.$count;
        }

        return ['known' => $known, 'new' => $new, 'stale' => $stale, 'errors' => $errors];
    }

    /** @return list<DomainMutationFinding> */
    private function scanFile(string $absolutePath, string $path): array
    {
        $code = file_get_contents($absolutePath);
        if ($code === false) {
            throw new RuntimeException("Cannot read [{$absolutePath}].");
        }

        try {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($code) ?? [];
        } catch (\Throwable $exception) {
            throw new RuntimeException("Cannot parse [{$path}]: {$exception->getMessage()}", 0, $exception);
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver(new Collecting));
        $traverser->addVisitor(new ParentConnectingVisitor);
        $nodes = $traverser->traverse($nodes);
        $finder = new NodeFinder;

        if (preg_match('#^app/Omnify/Modules/([^/]+)/Services/[^/]+ServiceBase\.php$#', $path, $matches)) {
            $aggregate = $this->aggregateForModel($matches[1]);
            if ($aggregate !== null && preg_match('/@generated by omnify|auto-generated service/i', $code)) {
                return [new DomainMutationFinding(
                    $aggregate,
                    $path,
                    'generated_service',
                    'generic-crud',
                    $matches[1],
                    1,
                    $this->stableHash('generated-service|'.$path),
                )];
            }
        }

        $findings = [];
        if (preg_match('#^app/Services/Omnify/([^/]+)Service\.php$#', $path, $matches)) {
            $aggregate = $this->aggregateForModel($matches[1]);
            if ($aggregate !== null && preg_match('/extends\s+[\\\\\w]*ServiceBase\b/', $code)) {
                $findings[] = new DomainMutationFinding(
                    $aggregate,
                    $path,
                    'generated_service',
                    'compatibility-facade',
                    $matches[1],
                    1,
                    $this->stableHash('compatibility-facade|'.$path),
                );
            }
        }

        $propertySources = [];
        $calls = $finder->find($nodes, static fn (Node $node): bool => $node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall || $node instanceof Expr\StaticCall);
        $siteOccurrences = [];

        foreach ($calls as $call) {
            /** @var Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $call */
            $symbol = $this->callName($call);
            $siteBase = $this->callSite($call);
            $siteOrdinal = $siteOccurrences[$siteBase] ?? 0;
            $siteOccurrences[$siteBase] = $siteOrdinal + 1;
            $site = $this->stableHash($siteBase.'|'.$siteOrdinal);
            if ($this->isRawDbCall($call)) {
                if ($symbol === null) {
                    $findings[] = new DomainMutationFinding('unknown', $path, 'unknown', 'dynamic-call', 'dynamic-db-call', $call->getStartLine(), $site);
                } elseif (in_array($symbol, self::RAW_DB_MUTATORS, true)) {
                    $sql = $this->stringArgument($call);
                    if ($sql === null) {
                        $findings[] = new DomainMutationFinding('unknown', $path, 'unknown', $symbol, 'dynamic-sql', $call->getStartLine(), $site);
                    } else {
                        foreach ($this->tables as $table => $aggregate) {
                            if (preg_match('/\b'.preg_quote($table, '/').'\b/i', $sql)) {
                                $findings[] = new DomainMutationFinding($aggregate, $path, 'raw_table', $symbol, $table, $call->getStartLine(), $site);
                            }
                        }
                    }
                }
            }

            $receiver = $call instanceof Expr\StaticCall ? $call->class : $call->var;
            $class = $this->enclosingClass($call);
            $classKey = $class === null ? 0 : spl_object_id($class);
            $propertySources[$classKey] ??= $this->propertySources($nodes, $class);
            $variables = $this->variablesAt($call, $nodes, $propertySources[$classKey]);
            $source = $this->source($receiver, $variables);
            if ($source === null) {
                continue;
            }

            if ($symbol === null) {
                $findings[] = new DomainMutationFinding('unknown', $path, 'unknown', 'dynamic-call', $source[2], $call->getStartLine(), $site);

                continue;
            }

            if ($source[1] === 'generated_service') {
                if (in_array($symbol, self::GENERATED_SERVICE_MUTATORS, true)) {
                    foreach ($this->sourceAggregates($source) as $aggregate) {
                        $findings[] = new DomainMutationFinding($aggregate, $path, 'generated_service_consumer', $symbol, $source[2], $call->getStartLine(), $site);
                    }
                }

                continue;
            }

            if ($source[1] === 'dynamic_service') {
                if (in_array($symbol, self::MUTATORS, true) || in_array($symbol, self::GENERATED_SERVICE_MUTATORS, true)) {
                    $findings[] = new DomainMutationFinding('unknown', $path, 'unknown', $symbol, 'dynamic-container-service', $call->getStartLine(), $site);
                }

                continue;
            }

            if (! in_array($symbol, self::MUTATORS, true)) {
                continue;
            }

            if (in_array($source[1], ['collection', 'value_collection', 'array_collection'], true)) {
                continue;
            }

            if (in_array('unknown', $this->sourceAggregates($source), true) || $source[1] === 'dynamic_table') {
                $findings[] = new DomainMutationFinding('unknown', $path, 'unknown', $symbol, 'dynamic-table', $call->getStartLine(), $site);

                continue;
            }

            $kind = in_array($source[1], ['table', 'query'], true)
                ? 'query_builder'
                : ($source[1] === 'relationship' ? 'relationship' : 'model');
            foreach ($this->sourceAggregates($source) as $aggregate) {
                $findings[] = new DomainMutationFinding($aggregate, $path, $kind, $symbol, $this->targetName($receiver, $source), $call->getStartLine(), $site);
            }
        }

        return $findings;
    }

    /**
     * @param  list<Node>  $nodes
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private function propertySources(array $nodes, ?Stmt\ClassLike $class): array
    {
        $finder = new NodeFinder;
        $properties = [];

        foreach ($finder->findInstanceOf($nodes, Stmt\Property::class) as $property) {
            if ($this->enclosingClass($property) !== $class) {
                continue;
            }
            $source = $this->sourceForType($property->type);
            if ($source !== null) {
                foreach ($property->props as $prop) {
                    $properties['this->'.$prop->name->toString()] = $source;
                }
            }
        }

        foreach ($finder->findInstanceOf($nodes, Stmt\ClassMethod::class) as $method) {
            if ($this->enclosingClass($method) !== $class) {
                continue;
            }

            $variables = [];
            foreach ($method->params as $parameter) {
                if ($parameter->var instanceof Expr\Variable && is_string($parameter->var->name)) {
                    $source = $this->sourceForType($parameter->type);
                    if ($source !== null) {
                        $variables[$parameter->var->name] = $source;
                        if ($parameter->flags !== 0) {
                            $properties['this->'.$parameter->var->name] = $source;
                        }
                    }
                }
            }

            $events = $finder->find($method->stmts ?? [], static fn (Node $node): bool => $node instanceof Expr\Assign || $node instanceof Stmt\Foreach_);
            usort($events, static fn (Node $left, Node $right): int => $left->getStartFilePos() <=> $right->getStartFilePos());
            foreach ($events as $event) {
                if ($this->enclosingFunction($event) !== $method) {
                    continue;
                }

                if ($event instanceof Stmt\Foreach_ && $event->valueVar instanceof Expr\Variable && is_string($event->valueVar->name)) {
                    $source = $this->source($event->expr, $variables + $properties);
                    if ($source !== null) {
                        $variables[$event->valueVar->name] = [$source[0], 'instance', $source[2]];
                    }

                    continue;
                }

                if (! $event instanceof Expr\Assign) {
                    continue;
                }

                $source = $this->source($event->expr, $variables + $properties);
                $variable = $this->assignedVariableName($event->var);
                if ($variable !== null) {
                    if ($source === null) {
                        if (! $this->isConditionalEvent($event)) {
                            unset($variables[$variable]);
                        }
                    } else {
                        $variables[$variable] = $event->var instanceof Expr\ArrayDimFetch
                            ? [$source[0], 'array_collection', $source[2]]
                            : $source;
                    }

                    continue;
                }

                $property = $this->assignedPropertyName($event->var);
                if ($property === null) {
                    continue;
                }
                $key = 'this->'.$property;
                if ($source !== null) {
                    $properties[$key] = $event->var instanceof Expr\ArrayDimFetch
                        ? [$source[0], 'array_collection', $source[2]]
                        : $source;
                }
            }
        }

        return $properties;
    }

    /**
     * @param  list<Node>  $nodes
     * @param  array<string, array{0: string, 1: string, 2: string}>  $properties
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private function variablesAt(Node $call, array $nodes, array $properties): array
    {
        $scope = $this->enclosingFunction($call);
        $variables = $properties;
        if ($scope === null) {
            return $variables;
        }

        if ($scope instanceof Expr\Closure) {
            $outer = $this->variablesAt($scope, $nodes, $properties);
            foreach ($scope->uses as $use) {
                if (is_string($use->var->name) && isset($outer[$use->var->name])) {
                    $variables[$use->var->name] = $outer[$use->var->name];
                }
            }
        } elseif ($scope instanceof Expr\ArrowFunction) {
            $variables += $this->variablesAt($scope, $nodes, $properties);
        }

        foreach ($scope->getParams() as $parameter) {
            if ($parameter->var instanceof Expr\Variable && is_string($parameter->var->name)) {
                $source = $this->sourceForType($parameter->type);
                if ($source !== null) {
                    $variables[$parameter->var->name] = $source;
                }
            }
        }

        $finder = new NodeFinder;
        $events = $finder->find($scope->getStmts() ?? [], static fn (Node $node): bool => $node instanceof Expr\Assign || $node instanceof Stmt\Foreach_);
        usort($events, static fn (Node $left, Node $right): int => $left->getStartFilePos() <=> $right->getStartFilePos());

        foreach ($events as $event) {
            if ($event->getStartFilePos() >= $call->getStartFilePos() || $this->enclosingFunction($event) !== $scope) {
                continue;
            }

            if ($event instanceof Expr\Assign) {
                $source = $this->source($event->expr, $variables);
                $variable = $this->assignedVariableName($event->var);
                if ($variable !== null) {
                    if ($source === null) {
                        if (! $this->isConditionalEvent($event)) {
                            unset($variables[$variable]);
                        }
                    } else {
                        $variables[$variable] = $event->var instanceof Expr\ArrayDimFetch
                            ? [$source[0], 'array_collection', $source[2]]
                            : $source;
                    }
                }
            }

            if ($event instanceof Stmt\Foreach_ && $event->valueVar instanceof Expr\Variable && is_string($event->valueVar->name)) {
                $source = $this->source($event->expr, $variables);
                if ($source === null) {
                    unset($variables[$event->valueVar->name]);
                } else {
                    $variables[$event->valueVar->name] = [$source[0], 'instance', $source[2]];
                }
            }
        }

        return $variables;
    }

    private function enclosingFunction(Node $node): ?FunctionLike
    {
        $parent = $node->getAttribute('parent');
        while ($parent instanceof Node) {
            if ($parent instanceof FunctionLike) {
                return $parent;
            }
            $parent = $parent->getAttribute('parent');
        }

        return null;
    }

    private function enclosingClass(Node $node): ?Stmt\ClassLike
    {
        $parent = $node->getAttribute('parent');
        while ($parent instanceof Node) {
            if ($parent instanceof Stmt\ClassLike) {
                return $parent;
            }
            $parent = $parent->getAttribute('parent');
        }

        return null;
    }

    /**
     * @param  array<string, array{0: string, 1: string, 2: string}>  $variables
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function source(Node $node, array $variables): ?array
    {
        if ($node instanceof Expr\Variable && is_string($node->name)) {
            return $variables[$node->name] ?? null;
        }
        if ($node instanceof Expr\BinaryOp\Coalesce) {
            return $this->mergeSources(
                $this->source($node->left, $variables),
                $this->source($node->right, $variables),
            );
        }
        if ($node instanceof Expr\Ternary) {
            return $this->mergeSources(
                $node->if === null ? null : $this->source($node->if, $variables),
                $this->source($node->else, $variables),
            );
        }
        if ($node instanceof Expr\Array_) {
            $source = null;
            foreach ($node->items as $item) {
                if ($item !== null) {
                    $source = $this->mergeSources($source, $this->source($item->value, $variables));
                }
            }

            return $source === null ? null : [$source[0], 'array_collection', $source[2]];
        }
        if ($node instanceof Expr\ArrayDimFetch) {
            $source = $this->source($node->var, $variables);

            return $source === null || $source[1] === 'value_collection'
                ? null
                : [$source[0], 'instance', $source[2]];
        }
        if ($node instanceof Name) {
            return $this->sourceForName($node);
        }
        if ($node instanceof Expr\New_ && $node->class instanceof Name) {
            return $this->sourceForName($node->class);
        }
        if ($node instanceof Expr\StaticCall) {
            if ($this->isDbFacadeName($node->class) && $this->callName($node) === 'table') {
                $table = $this->stringArgument($node);
                if ($table === null) {
                    return ['unknown', 'dynamic_table', 'dynamic-table'];
                }

                return isset($this->tables[$table]) ? [$this->tables[$table], 'table', $table] : null;
            }
            if ($this->isDbFacadeName($node->class) && $this->callName($node) === 'query') {
                return ['unknown', 'db_query', 'dynamic-table'];
            }
            $source = $node->class instanceof Name ? $this->sourceForName($node->class) : null;
            if ($source !== null && $source[1] === 'instance') {
                $symbol = $this->callName($node);
                if (in_array($symbol, self::MODEL_COLLECTION_TERMINALS, true)) {
                    return [$source[0], 'collection', $source[2]];
                }
                if (in_array($symbol, self::VALUE_COLLECTION_TERMINALS, true)) {
                    return [$source[0], 'value_collection', $source[2]];
                }
                if (in_array($symbol, self::INSTANCE_TERMINALS, true)) {
                    return [$source[0], 'instance', $source[2]];
                }

                return [$source[0], in_array($symbol, self::QUERY_METHODS, true) ? 'query' : 'instance', $source[2]];
            }

            return $source;
        }
        if ($node instanceof Expr\FuncCall && $node->name instanceof Name && in_array(strtolower($node->name->toString()), ['app', 'resolve'], true)) {
            $argument = $node->args[0]->value ?? null;
            if ($argument instanceof Expr\ClassConstFetch && $argument->class instanceof Name && $argument->name instanceof Node\Identifier && strtolower($argument->name->toString()) === 'class') {
                return $this->sourceForName($argument->class);
            }

            return ['unknown', 'dynamic_service', 'dynamic-container-service'];
        }
        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            if ($this->callName($node) === 'table' && $this->isDbConnectionExpression($node->var)) {
                $table = $this->methodStringArgument($node);
                if ($table === null) {
                    return ['unknown', 'dynamic_table', 'dynamic-table'];
                }

                return isset($this->tables[$table]) ? [$this->tables[$table], 'table', $table] : null;
            }
            if ($this->callName($node) === 'query' && $this->isDbConnectionExpression($node->var)) {
                return ['unknown', 'db_query', 'dynamic-table'];
            }
            $parent = $this->source($node->var, $variables);
            if ($parent === null) {
                return null;
            }
            if ($this->callName($node) === 'from' && $parent[1] === 'db_query') {
                $table = $this->stringArgument($node);
                if ($table === null) {
                    return ['unknown', 'dynamic_table', 'dynamic-table'];
                }

                return isset($this->tables[$table]) ? [$this->tables[$table], 'table', $table] : null;
            }
            if ($parent[1] === 'generated_service') {
                return in_array($this->callName($node), self::GENERATED_SERVICE_MUTATORS, true)
                    ? [$parent[0], 'instance', $parent[2]]
                    : $parent;
            }
            if (in_array($this->callName($node), self::COLLECTION_PRESERVING_METHODS, true)) {
                return [$parent[0], 'collection', $parent[2]];
            }
            if ($parent[1] === 'collection') {
                return in_array($this->callName($node), self::COLLECTION_ITEM_TERMINALS, true)
                    ? [$parent[0], 'instance', $parent[2]]
                    : $parent;
            }
            if (in_array($this->callName($node), self::MODEL_COLLECTION_TERMINALS, true)) {
                return [$parent[0], 'collection', $parent[2]];
            }
            if (in_array($this->callName($node), self::VALUE_COLLECTION_TERMINALS, true)) {
                return [$parent[0], 'value_collection', $parent[2]];
            }
            if (in_array($this->callName($node), self::INSTANCE_TERMINALS, true)) {
                return [$parent[0], 'instance', $parent[2]];
            }
            if (in_array($parent[1], ['table', 'dynamic_table'], true) || in_array($this->callName($node), self::QUERY_METHODS, true)) {
                return [$parent[0], $parent[1] === 'table' ? 'table' : ($parent[1] === 'dynamic_table' ? 'dynamic_table' : 'query'), $parent[2]];
            }

            return [$parent[0], 'relationship', $parent[2]];
        }
        if ($node instanceof Expr\PropertyFetch) {
            if ($node->var instanceof Expr\Variable && $node->var->name === 'this' && $node->name instanceof Node\Identifier) {
                return $variables['this->'.$node->name->toString()] ?? null;
            }

            $source = $this->source($node->var, $variables);
            if ($source !== null
                && $source[1] === 'collection'
                && $node->name instanceof Node\Identifier
                && in_array($node->name->toString(), self::COLLECTION_HIGHER_ORDER_PROXIES, true)) {
                return [$source[0], 'collection_proxy', $source[2]];
            }

            return $source;
        }

        return null;
    }

    /** @return array{0: string, 1: string, 2: string}|null */
    private function mergeSources(?array $left, ?array $right): ?array
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }

        $aggregates = array_values(array_unique([
            ...$this->sourceAggregates($left),
            ...$this->sourceAggregates($right),
        ]));
        sort($aggregates);

        return [implode(',', $aggregates), 'instance', count($aggregates) === 1 ? $left[2] : 'union-model'];
    }

    /** @param array{0: string, 1: string, 2: string} $source
     * @return list<string>
     */
    private function sourceAggregates(array $source): array
    {
        return explode(',', $source[0]);
    }

    private function assignedPropertyName(Node $node): ?string
    {
        if ($node instanceof Expr\ArrayDimFetch) {
            return $this->assignedPropertyName($node->var);
        }
        if ($node instanceof Expr\PropertyFetch
            && $node->var instanceof Expr\Variable
            && $node->var->name === 'this'
            && $node->name instanceof Node\Identifier) {
            return $node->name->toString();
        }

        return null;
    }

    private function assignedVariableName(Node $node): ?string
    {
        if ($node instanceof Expr\ArrayDimFetch) {
            return $this->assignedVariableName($node->var);
        }

        return $node instanceof Expr\Variable && is_string($node->name) ? $node->name : null;
    }

    private function isConditionalEvent(Node $event): bool
    {
        $parent = $event->getAttribute('parent');
        while ($parent instanceof Node && ! $parent instanceof FunctionLike) {
            if ($parent instanceof Stmt\If_
                || $parent instanceof Stmt\ElseIf_
                || $parent instanceof Stmt\Else_
                || $parent instanceof Stmt\Switch_
                || $parent instanceof Stmt\Case_
                || $parent instanceof Stmt\TryCatch
                || $parent instanceof Stmt\Catch_
                || $parent instanceof Stmt\Finally_
                || $parent instanceof Stmt\For_
                || $parent instanceof Stmt\Foreach_
                || $parent instanceof Stmt\While_
                || $parent instanceof Stmt\Do_) {
                return true;
            }
            $parent = $parent->getAttribute('parent');
        }

        return false;
    }

    private function callSite(Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $call): string
    {
        $class = $this->enclosingClass($call);
        $className = $class?->namespacedName ?? null;
        $path = [];
        $functions = [];
        $node = $call;

        while (($parent = $node->getAttribute('parent')) instanceof Node) {
            if ($parent instanceof Stmt\ClassLike) {
                break;
            }
            foreach ($parent->getSubNodeNames() as $name) {
                $child = $parent->{$name};
                if ($child === $node) {
                    $path[] = $name;
                    break;
                }
                if (is_array($child)) {
                    $index = array_search($node, $child, true);
                    if ($index !== false) {
                        $path[] = $name.':'.$index;
                        break;
                    }
                }
            }
            if ($parent instanceof FunctionLike) {
                $functions[] = match (true) {
                    $parent instanceof Stmt\ClassMethod => 'method:'.$parent->name->toString(),
                    $parent instanceof Stmt\Function_ => 'function:'.$parent->name->toString(),
                    $parent instanceof Expr\Closure => 'closure',
                    $parent instanceof Expr\ArrowFunction => 'arrow',
                    default => 'callable',
                };
            }
            $node = $parent;
        }

        $printer = new Standard;
        $identity = implode('|', [
            $className instanceof Name ? $className->toString() : 'global',
            implode('/', array_reverse($functions)) ?: 'file',
            implode('/', array_reverse($path)),
            $printer->prettyPrintExpr($call),
        ]);

        return $this->stableHash($identity);
    }

    private function stableHash(string $identity): string
    {
        return substr(hash('sha256', $identity), 0, 16);
    }

    private function discoverGeneratedServiceSubclass(string $path): bool
    {
        $code = file_get_contents($path);
        if ($code === false) {
            throw new RuntimeException("Cannot read [{$path}].");
        }

        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($code) ?? [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver(new Collecting));
        $nodes = $traverser->traverse($nodes);
        $discovered = false;

        foreach ((new NodeFinder)->findInstanceOf($nodes, Stmt\Class_::class) as $class) {
            if (! $class->extends instanceof Name) {
                continue;
            }
            $source = $this->sourceForName($class->extends);
            $className = $class->namespacedName ?? null;
            if ($source === null || $source[1] !== 'generated_service' || ! $className instanceof Name) {
                continue;
            }
            $fqcn = $className->toString();
            if (! isset($this->generatedServices[$fqcn])) {
                $this->generatedServices[$fqcn] = $source;
                $discovered = true;
            }
        }

        return $discovered;
    }

    /** @return array{0: string, 1: string, 2: string}|null */
    private function sourceForType(Node|string|null $type): ?array
    {
        if ($type instanceof Name) {
            return $this->sourceForName($type);
        }
        if ($type instanceof Node\NullableType) {
            return $this->sourceForType($type->type);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $sources = array_values(array_filter(array_map($this->sourceForType(...), $type->types)));
            if ($sources === []) {
                return null;
            }

            $merged = array_shift($sources);
            foreach ($sources as $source) {
                $merged = $this->mergeSources($merged, $source);
            }

            return $merged;
        }

        return null;
    }

    /** @return array{0: string, 1: string, 2: string}|null */
    private function sourceForName(Name $name): ?array
    {
        $class = $this->name($name);
        if (isset($this->models[$class])) {
            return [$this->models[$class], 'instance', substr($class, strrpos($class, '\\') + 1)];
        }
        if (isset($this->generatedServices[$class])) {
            return $this->generatedServices[$class];
        }

        if (preg_match('#^App\\\\Services\\\\Omnify\\\\([^\\\\]+)Service$#', $class, $matches)
            || preg_match('#^App\\\\Omnify\\\\Modules\\\\([^\\\\]+)\\\\Services\\\\[^\\\\]+Service(?:Base)?$#', $class, $matches)) {
            $aggregate = $this->aggregateForModel($matches[1]);

            return $aggregate === null ? null : [$aggregate, 'generated_service', $matches[1]];
        }

        return null;
    }

    private function aggregateForModel(string $model): ?string
    {
        return $this->models['App\\Models\\'.$model] ?? null;
    }

    private function name(Node $node): string
    {
        if (! $node instanceof Name) {
            return '';
        }
        $resolved = $node->getAttribute('resolvedName');

        return $resolved instanceof Name ? $resolved->toString() : $node->toString();
    }

    private function callName(Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $call): ?string
    {
        return $call->name instanceof Node\Identifier ? $call->name->toString() : null;
    }

    private function stringArgument(Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $call): ?string
    {
        $value = $call->args[0]->value ?? null;

        return $value instanceof Node\Scalar\String_ ? $value->value : null;
    }

    private function methodStringArgument(Expr\MethodCall|Expr\NullsafeMethodCall $call): ?string
    {
        return $this->stringArgument($call);
    }

    /** @param array{0: string, 1: string, 2: string} $source */
    private function targetName(Node $receiver, array $source): string
    {
        if ($receiver instanceof Name) {
            return substr($this->name($receiver), strrpos($this->name($receiver), '\\') + 1);
        }
        if (($receiver instanceof Expr\StaticCall || $receiver instanceof Expr\MethodCall || $receiver instanceof Expr\NullsafeMethodCall) && $this->callName($receiver) === 'table') {
            return $this->stringArgument($receiver) ?? 'dynamic-table';
        }
        if ($receiver instanceof Expr\MethodCall || $receiver instanceof Expr\NullsafeMethodCall) {
            return $this->callName($receiver) ?? 'dynamic-relation';
        }

        return $source[1] === 'instance' ? 'inferred-model' : $source[2];
    }

    private function isRawDbCall(Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $call): bool
    {
        if ($call instanceof Expr\StaticCall) {
            return $this->isDbFacadeName($call->class);
        }

        return $this->isDbConnectionExpression($call->var);
    }

    private function isDbConnectionExpression(Node $node): bool
    {
        return $node instanceof Expr\StaticCall
            && $this->isDbFacadeName($node->class)
            && $this->callName($node) === 'connection';
    }

    private function isDbFacadeName(Node $node): bool
    {
        return in_array($this->name($node), ['DB', 'Illuminate\\Support\\Facades\\DB'], true);
    }

    private function isBoundary(DomainMutationFinding $finding): bool
    {
        return isset($this->aggregates[$finding->aggregate])
            && in_array($finding->path, $this->aggregates[$finding->aggregate]['boundaries'], true);
    }

    private function isCanonicalExistingPath(string $path): bool
    {
        if ($this->scanRoot === null
            || ! str_starts_with($path, 'app/')
            || str_contains($path, '\\')
            || str_contains($path, '..')
            || str_contains($path, '//')) {
            return false;
        }

        $absolute = realpath($this->scanRoot.'/'.$path);
        if ($absolute === false || ! is_file($absolute)) {
            return false;
        }

        return str_replace('\\', '/', substr($absolute, strlen($this->scanRoot) + 1)) === $path;
    }
}
