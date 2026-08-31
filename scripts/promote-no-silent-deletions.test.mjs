// #3093 — cổng promote phải chứng minh CẢ HAI chiều: biết kêu khi hotfix sắp
// bị nuốt, và biết IM khi `dev` xoá có chủ đích.
//
// Bản đầu của cổng chỉ chứng minh được vế "biết kêu" — và ở #3091 nó kêu nhầm.
// Một rào kêu oan không bị tranh luận, nó bị tắt, nên chiều IM đáng được ghim
// bằng đúng công sức của chiều KÊU.
//
// Dựng repo git thật trong thư mục tạm thay vì mock: phép phân biệt nằm ở
// `merge-base --is-ancestor`, tức ở chính hình dạng đồ thị commit. Mock đồ thị
// đó là mock chính thứ đang được đo.

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { mkdtempSync, rmSync, writeFileSync, mkdirSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, dirname } from 'node:path'

import { audit } from './promote-no-silent-deletions.mjs'

function run(cwd, ...args) {
  execFileSync('git', args, { cwd, stdio: 'ignore' })
}

function commit(cwd, message) {
  run(cwd, 'add', '-A')
  execFileSync('git', ['commit', '-q', '-m', message], {
    cwd,
    stdio: 'ignore',
    env: {
      ...process.env,
      GIT_AUTHOR_NAME: 't',
      GIT_AUTHOR_EMAIL: 't@example.com',
      GIT_COMMITTER_NAME: 't',
      GIT_COMMITTER_EMAIL: 't@example.com',
      GIT_AUTHOR_DATE: '2026-01-01T00:00:00Z',
      GIT_COMMITTER_DATE: '2026-01-01T00:00:00Z',
    },
  })
}

function write(cwd, path, body) {
  const full = join(cwd, path)
  mkdirSync(dirname(full), { recursive: true })
  writeFileSync(full, body)
}

/** Repo có `dev` và `main`, cùng một commit gốc. */
function newRepo() {
  const cwd = mkdtempSync(join(tmpdir(), 'promote-gate-'))
  run(cwd, 'init', '-q', '-b', 'dev')
  write(cwd, 'README.md', 'gốc\n')
  commit(cwd, 'gốc')
  run(cwd, 'branch', 'main')
  return cwd
}

function paths(results) {
  return results.map((r) => r.path).sort()
}

test('KÊU: hotfix chỉ có trên main, chưa bao giờ về dev', () => {
  const cwd = newRepo()
  try {
    run(cwd, 'checkout', '-q', 'main')
    write(cwd, 'backend/tests/react crash guard.test.tsx', 'ghim React #310\n')
    commit(cwd, 'hotfix: ghim React #310')

    const lost = audit({ base: 'dev', head: 'main', cwd }).filter((r) => !r.deliberate)

    assert.deepEqual(paths(lost), ['backend/tests/react crash guard.test.tsx'])
    assert.match(lost[0].reason, /base chưa bao giờ nhận/)
  } finally {
    rmSync(cwd, { recursive: true, force: true })
  }
})

test('IM: dev từng có file rồi xoá có chủ đích (ca thật #2410/#3091)', () => {
  const cwd = newRepo()
  try {
    // Lớp alias ra đời trên `dev` và được mang lên `main` — cùng MỘT commit.
    write(cwd, 'backend/app/Http/Support/LegacyPaymentPolicyFieldAliases.php', '<?php // alias\n')
    commit(cwd, 'fix(payment): nhận tên trường policy fleet đang gửi')
    run(cwd, 'checkout', '-q', 'main')
    run(cwd, 'merge', '-q', '--ff-only', 'dev')

    // Cổng readiness trả DELETE NOW ⇒ `dev` xoá.
    run(cwd, 'checkout', '-q', 'dev')
    rmSync(join(cwd, 'backend/app/Http/Support/LegacyPaymentPolicyFieldAliases.php'))
    commit(cwd, 'refactor(payments): XOÁ lớp alias — readiness nói DELETE NOW')

    const results = audit({ base: 'dev', head: 'main', cwd })

    assert.equal(results.length, 1, 'diff vẫn thấy path — đây chính là ca cổng cũ kêu nhầm')
    assert.equal(results[0].deliberate, true)
    assert.equal(audit({ base: 'dev', head: 'main', cwd }).filter((r) => !r.deliberate).length, 0)
  } finally {
    rmSync(cwd, { recursive: true, force: true })
  }
})

test('KÊU: cherry-pick đổi SHA ⇒ ở lại đỏ, đúng chiều sai an toàn', () => {
  const cwd = newRepo()
  try {
    run(cwd, 'checkout', '-q', 'main')
    write(cwd, 'backend/app/Fix.php', '<?php // vá nóng\n')
    commit(cwd, 'hotfix trên main')
    const hotfix = execFileSync('git', ['rev-parse', 'HEAD'], { cwd, encoding: 'utf8' }).trim()

    // Mang về `dev` bằng cherry-pick — nội dung giống, SHA khác — rồi xoá.
    run(cwd, 'checkout', '-q', 'dev')
    execFileSync('git', ['cherry-pick', hotfix], {
      cwd,
      stdio: 'ignore',
      env: { ...process.env, GIT_COMMITTER_NAME: 't', GIT_COMMITTER_EMAIL: 't@example.com' },
    })
    rmSync(join(cwd, 'backend/app/Fix.php'))
    commit(cwd, 'xoá lại')

    const lost = audit({ base: 'dev', head: 'main', cwd }).filter((r) => !r.deliberate)

    assert.deepEqual(paths(lost), ['backend/app/Fix.php'])
  } finally {
    rmSync(cwd, { recursive: true, force: true })
  }
})

// Ca này bị chặn ở phép kiểm THỨ NHẤT ("base chưa bao giờ nhận commit thêm"),
// không phải ở phép kiểm hậu-duệ — tôi viết test này với giả thiết ngược lại và
// nó đỏ, nên ghim VERDICT chứ không ghim lý do. Xem chú thích ở `classify()`:
// phép kiểm hậu-duệ là phòng thủ, chưa dựng được ca nào với tới nó.
test('KÊU: một lần xoá CŨ trùng path không được nhận công cho hotfix mới', () => {
  const cwd = newRepo()
  try {
    // `dev` từng có `backend/app/Fix.php` từ đời nào rồi xoá — không liên quan.
    write(cwd, 'backend/app/Fix.php', '<?php // bản đời đầu\n')
    commit(cwd, 'bản đời đầu')
    rmSync(join(cwd, 'backend/app/Fix.php'))
    commit(cwd, 'xoá bản đời đầu')

    // Rồi một hotfix MỚI tạo lại đúng path đó, chỉ trên `main`.
    run(cwd, 'checkout', '-q', 'main')
    run(cwd, 'merge', '-q', '--ff-only', 'dev')
    write(cwd, 'backend/app/Fix.php', '<?php // vá nóng, nội dung khác hẳn\n')
    commit(cwd, 'hotfix trên main')

    const lost = audit({ base: 'dev', head: 'main', cwd }).filter((r) => !r.deliberate)

    assert.deepEqual(paths(lost), ['backend/app/Fix.php'])
  } finally {
    rmSync(cwd, { recursive: true, force: true })
  }
})

test('IM: dev và main giống nhau ⇒ không có gì để nói', () => {
  const cwd = newRepo()
  try {
    assert.deepEqual(audit({ base: 'dev', head: 'main', cwd }), [])
  } finally {
    rmSync(cwd, { recursive: true, force: true })
  }
})
