import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { test } from "node:test";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

import { parse } from "yaml";

import { pathsCover } from "./lib/workflow-paths.mjs";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const workflow = readFileSync(join(root, ".github/workflows/omnify-gate.yml"), "utf8");

test("Omnify gate watches every input that can change generated state or its guards", () => {
  const on = parse(workflow).on ?? parse(workflow)[true];

  assert.ok(on, "không đọc được khối 'on:' — bài này sẽ vô nghĩa");

  for (const event of ["push", "pull_request"]) {
    assert.deepEqual(
      on[event]?.branches,
      ["dev", "main"],
      `on.${event}.branches phải là [dev, main]`,
    );
  }

  for (const subject of [
    ".omnify/**",
    "scripts/**",
    "schemas/**",
    "omnify.yaml",
    "package.json",
    "package-lock.json",
    // #2971 — trước đây ghim đúng `omnify-gate.yml`. Chủ thể thật là cả cây
    // workflow: `deploy-xserver.yml` (ghi vào DB production) cũng phải kéo
    // được gate.
    ".github/workflows/deploy-xserver.yml",
    ".github/workflows/omnify-gate.yml",
  ]) {
    for (const event of ["push", "pull_request"]) {
      // So theo PHỦ, không theo chuỗi nguyên văn: `.github/workflows/**` phủ
      // `.github/workflows/deploy-xserver.yml` dù hai chuỗi khác nhau. Bản cũ
      // đếm chuỗi và đỏ khi bộ lọc được MỞ RỘNG — trả lời sai câu hỏi (#2971).
      assert.ok(
        pathsCover(on[event]?.paths ?? [], subject),
        `on.${event}.paths không phủ ${subject} — lượt chạy chạm nó sẽ không kéo gate`,
      );
    }
  }
});

test("Omnify gate runs on the supported self-hosted runner", () => {
  assert.match(workflow, /runs-on: \[self-hosted, Linux, X64\]/);
  assert.doesNotMatch(workflow, /runs-on: ubuntu-latest/);
});

test("Omnify gate runs the version, drift, and regression guards", () => {
  const commands = [
    "npm run omnify:check",
    "npm run omnify:drift",
    "npm run test:omnify-drift",
    "npm run test:omnify-ci",
  ];
  let cursor = -1;

  for (const command of commands) {
    const next = workflow.indexOf(command, cursor + 1);
    assert.ok(next > cursor, `${command} must run after the preceding guard`);
    cursor = next;
  }
});
