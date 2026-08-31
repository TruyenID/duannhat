<?php

declare(strict_types=1);

namespace App\Architecture;

final readonly class DomainMutationFinding
{
    public function __construct(
        public string $aggregate,
        public string $path,
        public string $kind,
        public string $symbol,
        public string $target,
        public int $line,
        public string $site,
    ) {}

    public function key(): string
    {
        return implode('|', [$this->aggregate, $this->path, $this->kind, $this->symbol, $this->target, $this->site]);
    }

    /** @return array{aggregate: string, path: string, kind: string, symbol: string, target: string, line: int, site: string} */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
