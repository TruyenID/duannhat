import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { test } from "node:test";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

/**
 * #2557 — pins WHEN each gate in `backend-tests.yml` runs.
 *
 * The bug this exists for did not touch the workflow at all. `arch-gate` was
 * conditioned on `base_ref != 'main'`, which was correct while PRs targeted
 * `dev`; the day every PR started targeting `main` the condition went
 * permanently false and the job — the only place `deptrac` runs — stopped
 * firing. Nothing turned red, because a job that never starts cannot fail.
 *
 * So these assert the CONDITIONS, not the steps. A workflow can be perfectly
 * well-formed, pass YAML lint, and still gate nothing.
 */

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const workflow = readFileSync(join(root, ".github/workflows/backend-tests.yml"), "utf8");
const deploy = readFileSync(join(root, ".github/workflows/deploy-xserver.yml"), "utf8");

/** The `if:` expression of a job, with comments and newlines flattened out. */
function jobCondition(name) {
  const start = workflow.indexOf(`\n  ${name}:\n`);
  assert.notEqual(start, -1, `job ${name} is gone`);
  const body = workflow.slice(start, workflow.indexOf("\n    runs-on:", start));

  return body
    .split("\n")
    .filter((line) => !line.trim().startsWith("#"))
    .join(" ")
    .replace(/\s+/g, " ");
}

test("arch-gate runs on pull requests — it is the only place deptrac runs", () => {
  // The regression, stated as an invariant. `arch-gate` must not be excluded on
  // any branch name: the moment its condition mentions a specific base, it
  // becomes hostage to the repo's branch layout again.
  const condition = jobCondition("arch-gate");

  assert.doesNotMatch(
    condition,
    /base_ref/,
    "arch-gate must not depend on base_ref — that is exactly how it went silent",
  );
  assert.match(condition, /github\.event_name != 'schedule'/);

  // And it really is the only deptrac site, which is why the above matters.
  const deptracJobs = workflow
    .split(/\n  (?=[a-z-]+:\n)/)
    .filter((chunk) => chunk.includes("deptrac analyse"));
  assert.equal(deptracJobs.length, 1, "deptrac must live in exactly one job");
  assert.ok(deptracJobs[0].startsWith("arch-gate:"), "deptrac must live in arch-gate");
});

test("the full suite is the dev→main promotion gate, not a per-PR gate", () => {
  const condition = jobCondition("pest");

  // Runs for the promotion PR (base main) and for code already on main.
  assert.match(condition, /github\.base_ref == 'main'/);
  assert.match(condition, /github\.ref == 'refs\/heads\/main'/);

  // Does NOT run merely because something landed on the integration branch —
  // that would put 14 minutes on every PR merge, which is the cost this
  // arrangement exists to avoid.
  assert.doesNotMatch(condition, /refs\/heads\/dev/);
});

test("the full-suite label can actually start a run", () => {
  // The valve is worthless without `labeled`: the default pull_request types
  // are [opened, synchronize, reopened], so labelling an OPEN pr triggers
  // nothing and the escape hatch silently does not exist.
  assert.match(jobCondition("pest"), /labels\.\*\.name, 'full-suite'/);

  const triggerBlock = workflow.slice(workflow.indexOf("on:"), workflow.indexOf("\nconcurrency:"));
  assert.match(triggerBlock, /types: \[opened, synchronize, reopened, labeled\]/);
});

test("both branches of the promotion path are watched", () => {
  const triggerBlock = workflow.slice(workflow.indexOf("on:"), workflow.indexOf("\nconcurrency:"));

  for (const event of ["push", "pull_request"]) {
    assert.match(
      triggerBlock,
      new RegExp(`\\n  ${event}:[\\s\\S]*?branches: \\[dev, main\\]`),
      `${event} must watch dev and main`,
    );
  }
});

test("a run on dev or main is never cancelled by the next push", () => {
  // On a PR, cancelling a superseded run costs nothing. On dev/main the running
  // job is the ONLY evidence for that commit, and a cancelled run reports
  // `cancelled` to `workflow_run` — so deploy-xserver skips the deploy too.
  const concurrency = workflow.slice(
    workflow.indexOf("\nconcurrency:"),
    workflow.indexOf("\njobs:"),
  );

  assert.match(concurrency, /cancel-in-progress: \$\{\{ github\.event_name == 'pull_request' \}\}/);
});

test("deploy-xserver still listens to this workflow by its real name", () => {
  // A rename here unhooks production deploy, and nothing else would notice:
  // `workflow_run` matches on the NAME string, so a mismatch simply never
  // fires rather than erroring.
  const name = workflow.match(/\nname: (.+)/)?.[1]?.trim();
  assert.ok(name, "backend-tests.yml must declare a name");
  assert.match(deploy, new RegExp(`workflows: \\[${name}\\]`));
});

test("nightly-only jobs stay nightly-only", () => {
  // timezone-matrix and flake-hunt are the two nets too slow for a push gate.
  // If either drifted onto the PR path it would put ~30x -race cost on every
  // change; if it drifted off `schedule` it would stop running at all.
  for (const job of ["timezone-matrix", "flake-hunt"]) {
    assert.match(
      jobCondition(job),
      /github\.event_name == 'schedule' \|\| github\.event_name == 'workflow_dispatch'/,
      `${job} must remain schedule/dispatch only`,
    );
  }
});
