package printjob

import (
	"go/ast"
	"go/parser"
	"go/token"
	"maps"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"unicode"
)

// plan-052 P-08 [HARD] — the ledger is a JOURNAL, never a gate.
//
// This is the one rule of the whole print pipeline that a shop feels
// immediately when it is broken: if recording a print could reach Cloud, then a
// Cloud outage — or merely a slow one — would stall or fail printing, and the
// workstation exists precisely so that cannot happen (plan-052 RISKS PR2).
//
// The rule is easy to break by accident and impossible to notice in
// development, where Cloud is always up and fast. So it is enforced
// structurally: this package may not import an HTTP client, and the print
// handlers may not call the sync engine's Cloud methods.

// TestJournalPackageHasNoNetworkDependency locks the journal itself.
func TestJournalPackageHasNoNetworkDependency(t *testing.T) {
	forbidden := []string{
		"net/http",
		"internal/service", // where cloudPost/cloudGet live
	}

	entries, err := os.ReadDir(".")
	if err != nil {
		t.Fatalf("read package dir: %v", err)
	}

	fset := token.NewFileSet()
	for _, entry := range entries {
		name := entry.Name()
		if entry.IsDir() || !strings.HasSuffix(name, ".go") || strings.HasSuffix(name, "_test.go") {
			continue
		}

		file, err := parser.ParseFile(fset, name, nil, parser.ImportsOnly)
		if err != nil {
			t.Fatalf("parse %s: %v", name, err)
		}

		for _, imp := range file.Imports {
			path := strings.Trim(imp.Path.Value, `"`)
			for _, bad := range forbidden {
				if path == bad || strings.HasSuffix(path, bad) {
					t.Errorf("%s imports %q — the print journal must never be able to reach Cloud (P-08). "+
						"Recording a print happens locally; the sync engine drains it afterwards.",
						name, path)
				}
			}
		}
	}
}

// TestPrintCriticalPathDoesNotCallCloud walks the handler package and fails if
// a function that PRINTS also calls a Cloud method.
//
// The check is deliberately shaped around the actual failure: it is not
// "handlers may not talk to Cloud" (they legitimately do — force-pulling a
// missing order before printing it is correct and already shipped). It is
// "nothing between resolving the printer and writing the bytes may block on
// Cloud". So it looks for a Cloud call in the same statement list, AFTER the
// Print call.
func TestPrintCriticalPathDoesNotCallCloud(t *testing.T) {
	handlerDir := filepath.Join("..", "handler")

	fset := token.NewFileSet()
	pkgs, err := parser.ParseDir(fset, handlerDir, func(fi os.FileInfo) bool {
		return !strings.HasSuffix(fi.Name(), "_test.go")
	}, 0)
	if err != nil {
		t.Fatalf("parse handler package: %v", err)
	}

	names := cloudCallNames()
	assertCloudCallNamesCanFire(t, names)

	// Vacuity floor. Everything below matches call sites by NAME in a package
	// this test parses from disk. Rename the methods, move the print handlers to
	// another package, or mistype the directory, and the walk inspects nothing —
	// which looks exactly like a clean tree. So first prove the matcher has at
	// least one real subject in the package it is pointed at.
	reachable := 0
	for _, pkg := range pkgs {
		for _, file := range pkg.Files {
			ast.Inspect(file, func(n ast.Node) bool {
				call, ok := n.(*ast.CallExpr)
				if !ok {
					return true
				}
				if sel, ok := call.Fun.(*ast.SelectorExpr); ok && names[sel.Sel.Name] {
					reachable++
				}
				return true
			})
		}
	}
	if reachable == 0 {
		t.Fatalf(
			"no call to any of %v appears anywhere in %s — this guard matches call sites by name, so a "+
				"rename or a move leaves it scanning a package it cannot fire on while still reporting green. "+
				"Point it at the handlers again, or update the name list; do not delete this floor.",
			slices.Sorted(maps.Keys(names)), handlerDir,
		)
	}

	for _, pkg := range pkgs {
		for fileName, file := range pkg.Files {
			ast.Inspect(file, func(n ast.Node) bool {
				block, ok := n.(*ast.BlockStmt)
				if !ok {
					return true
				}

				printedAt := -1
				for i, stmt := range block.List {
					if printedAt < 0 && callsMethodNamed(stmt, "Print") {
						printedAt = i
						continue
					}
					if printedAt >= 0 {
						if name, found := findCloudCall(stmt, names); found {
							t.Errorf("%s: %s is called AFTER a Print() in the same block — "+
								"the print path must never wait on Cloud (plan-052 P-08)",
								filepath.Base(fileName), name)
						}
					}
				}
				return true
			})
		}
	}
}

// cloudCallNames lists the calls that BLOCK on Cloud and that a print handler
// can actually make. Every name here is checked for reachability by
// assertCloudCallNamesCanFire — a name that cannot appear as a call in the
// scanned package is not a guard, it is a comment that looks like one.
//
// #3190 removed three such names. `ForcePullOrder` had no definition and no
// caller anywhere in the tree (`git grep ForcePullOrder` returned this line and
// nothing else), and `cloudPost` / `cloudGet` / `cloudDelete` are UNEXPORTED
// methods of package `service` — Go makes it impossible for the string
// `.cloudPost(` to appear in package `handler`, so those three could never fire
// either. Of five names, exactly one was doing any work.
//
// `RecoverOrderOnCloud` was added in their place: it is exported, it is called
// from the handler package (routes.go), and it waits on `cloudOrderExists`
// before returning — precisely the shape P-08 forbids downstream of a Print.
func cloudCallNames() map[string]bool {
	return map[string]bool{
		"PullOrderNow":        true,
		"RecoverOrderOnCloud": true,
	}
}

// assertCloudCallNamesCanFire proves each matcher name is something the scanned
// package could call at all: it must be declared under internal/, and it must
// be either exported or declared in the handler package itself. An unexported
// method of another package is unreachable by construction — listing it reads
// as coverage while adding none.
func assertCloudCallNamesCanFire(t *testing.T, names map[string]bool) {
	t.Helper()

	declaredIn := map[string]map[string]bool{}
	fset := token.NewFileSet()
	err := filepath.WalkDir("..", func(path string, d os.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if d.IsDir() || !strings.HasSuffix(path, ".go") || strings.HasSuffix(path, "_test.go") {
			return nil
		}
		file, perr := parser.ParseFile(fset, path, nil, 0)
		if perr != nil {
			return perr
		}
		for _, decl := range file.Decls {
			fn, ok := decl.(*ast.FuncDecl)
			if !ok {
				continue
			}
			if declaredIn[fn.Name.Name] == nil {
				declaredIn[fn.Name.Name] = map[string]bool{}
			}
			declaredIn[fn.Name.Name][file.Name.Name] = true
		}
		return nil
	})
	if err != nil {
		t.Fatalf("walk internal/ for declarations: %v", err)
	}

	// A floor on the floor: if the walk found nothing, it proves nothing.
	if len(declaredIn) < 100 {
		t.Fatalf("only %d function declarations found under internal/ — the reachability walk is broken, "+
			"so it would clear any name at all", len(declaredIn))
	}

	for _, name := range slices.Sorted(maps.Keys(names)) {
		pkgs, found := declaredIn[name]
		if !found {
			t.Errorf("%q is in the Cloud-call list but is declared nowhere under internal/ — "+
				"no call to it can ever appear, so this entry can never fire. Delete it, or point it at "+
				"the method that actually reaches Cloud.", name)
			continue
		}
		if unicode.IsUpper(rune(name[0])) || pkgs["handler"] {
			continue
		}
		t.Errorf("%q is unexported and declared only in package(s) %v, so the string %q can never appear "+
			"in package handler — this entry looks like coverage and provides none. Either guard the "+
			"exported method that handlers actually call, or drop the name.",
			name, slices.Sorted(maps.Keys(pkgs)), "."+name+"(")
	}
}

func callsMethodNamed(node ast.Node, method string) bool {
	found := false
	ast.Inspect(node, func(n ast.Node) bool {
		call, ok := n.(*ast.CallExpr)
		if !ok {
			return true
		}
		if sel, ok := call.Fun.(*ast.SelectorExpr); ok && sel.Sel.Name == method {
			found = true
			return false
		}
		return true
	})
	return found
}

func findCloudCall(node ast.Node, names map[string]bool) (string, bool) {
	var hit string
	ast.Inspect(node, func(n ast.Node) bool {
		call, ok := n.(*ast.CallExpr)
		if !ok {
			return true
		}
		if sel, ok := call.Fun.(*ast.SelectorExpr); ok && names[sel.Sel.Name] {
			hit = sel.Sel.Name
			return false
		}
		return true
	})
	return hit, hit != ""
}
