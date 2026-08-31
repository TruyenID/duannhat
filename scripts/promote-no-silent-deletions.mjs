#!/usr/bin/env node
// #2909 dựng, #3093 dạy nó phân biệt.
//
// Chặn một lượt promote `dev → main` âm thầm XOÁ file mà chỉ `main` có — một
// hotfix merge thẳng vào `main` rồi quên mang ngược về `dev` sẽ bị lượt promote
// kế hoàn tác, và diff của lượt đó trông y hệt một PR bình thường. Ca thật
// 2026-08-15: 271 dòng test ghim React #310 nằm im hai ngày trên `main`.
//
// Bản đầu đo bằng một diff `--diff-filter=A dev..main`, mà diff chỉ trả lời
// "path có tồn tại không". Hai chuyện khác hẳn nhau cho ra cùng kết quả:
//
//   hotfix chưa bao giờ về `dev`      ⇒ PHẢI chặn, promote sẽ nuốt mất nó
//   `dev` từng có rồi xoá có chủ đích ⇒ KHÔNG chặn, đó là nội dung đang promote
//
// Ca thứ hai xảy ra thật ở #3091 (lớp alias tên trường policy, xoá ở #2410 sau
// khi cổng readiness chạy trên production trả DELETE NOW). Rào kêu oan không bị
// tranh luận, nó bị tắt — nên phép phân biệt phải hỏi LỊCH SỬ, không hỏi cây:
//
//   1. commit THÊM file trên `main` có phải tổ tiên của `dev` không
//      (⇔ `dev` đã từng nhận đúng file này chưa), và
//   2. `dev` có commit XOÁ path đó, là hậu duệ của commit thêm, không.
//
// Đủ cả hai ⇒ xoá có chủ đích. Thiếu bất kỳ vế nào ⇒ giữ đỏ. Cherry-pick đổi
// SHA nên vế (1) sẽ trượt và cổng ở lại đỏ — ĐÚNG CHIỀU SAI AN TOÀN cho một rào
// canh tiền: thừa một dấu đỏ thì có người đọc, thiếu một dấu đỏ thì mất việc.

import { execFileSync } from 'node:child_process'

export function git(args, cwd) {
  return execFileSync('git', args, { cwd, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
}

function gitOk(args, cwd) {
  try {
    execFileSync('git', args, { cwd, stdio: 'ignore' })
    return true
  } catch {
    return false
  }
}

/**
 * Path CÓ ở `head`, KHÔNG có ở `base`.
 *
 * `--no-renames` cố ý: câu hỏi là path có TỒN TẠI không, không phải Git đoán
 * được cặp đổi tên hay không. `-z` vì đã có path chứa khoảng trắng thật
 * ("react crash guard.test.tsx") — tách theo dòng sẽ in sai tên file và kèm
 * hướng dẫn cherry-pick sai.
 */
function pathsOnlyOnHead(base, head, cwd) {
  return git(['diff', '--name-only', '-z', '--no-renames', '--diff-filter=A', `${base}..${head}`, '--'], cwd)
    .split('\0')
    .filter((p) => p !== '')
}

/** Xoá trên `base` có chủ đích, hay hotfix của `head` chưa bao giờ về `base`? */
export function classify(path, base, head, cwd) {
  const addedOnHead = git(['log', head, '--diff-filter=A', '--format=%H', '-1', '--', path], cwd).trim()
  if (addedOnHead === '') {
    return { path, deliberate: false, reason: 'không tìm được commit thêm file trên head' }
  }

  if (!gitOk(['merge-base', '--is-ancestor', addedOnHead, base], cwd)) {
    return { path, deliberate: false, reason: `base chưa bao giờ nhận ${addedOnHead.slice(0, 9)}` }
  }

  const deletedOnBase = git(['log', base, '--diff-filter=D', '--format=%H', '-1', '--', path], cwd).trim()
  if (deletedOnBase === '') {
    return { path, deliberate: false, reason: 'base không có commit xoá path này' }
  }

  // Xoá phải là HẬU DUỆ của commit thêm. Thiếu vế này thì một lần xoá cũ, không
  // liên quan, trùng path sẽ tự nhận công cho một hotfix mới.
  //
  // Vế này là PHÒNG THỦ và hiện KHÔNG có ca nào với tới được — đã thử dựng và
  // thất bại, ghi lại để người sau đừng mất công: muốn tới đây thì `addedOnHead`
  // phải vừa là tổ tiên của `base`, vừa mới hơn lần xoá cuối của `base`; mà một
  // commit THÊM file, nằm trong lịch sử `base`, không có lần xoá nào sau nó, thì
  // `base` đang CÓ file — và path đã không lọt vào diff ngay từ đầu. Giữ lại vì
  // nó rẻ và vì "hiện không dựng được" không phải "vĩnh viễn không xảy ra"; ca
  // gần nhất là xoá bằng resolution của một merge commit, thứ mà
  // `--diff-filter=D` bỏ qua theo mặc định — ca đó rơi vào nhánh ngay trên và
  // cũng ra ĐỎ, đúng chiều.
  if (!gitOk(['merge-base', '--is-ancestor', addedOnHead, deletedOnBase], cwd)) {
    return { path, deliberate: false, reason: 'commit xoá ở base có trước commit thêm ở head' }
  }

  return { path, deliberate: true, reason: deletedOnBase }
}

export function audit({ base, head, cwd }) {
  return pathsOnlyOnHead(base, head, cwd).map((p) => classify(p, base, head, cwd))
}

function main() {
  const cwd = process.cwd()
  const base = process.env.PROMOTE_BASE ?? 'origin/dev'
  const head = process.env.PROMOTE_HEAD ?? 'origin/main'

  const results = audit({ base, head, cwd })
  const lost = results.filter((r) => !r.deliberate)
  const deliberate = results.filter((r) => r.deliberate)
  const summary = []

  if (deliberate.length > 0) {
    summary.push(
      `## ${deliberate.length} file bị xoá CÓ CHỦ ĐÍCH trên \`${base}\` — không chặn`,
      '',
      ...deliberate.map((r) => `- \`${r.path}\` — xoá ở \`${r.reason.slice(0, 9)}\``),
      ''
    )
    console.log(`OK — ${deliberate.length} file xoá có chủ đích (base từng có rồi xoá).`)
  }

  if (lost.length === 0) {
    console.log(`OK — ${head} không có file nào vắng mặt ở ${base} mà không giải thích được.`)
    writeSummary(summary)
    return 0
  }

  summary.push(
    `## Promote bị chặn — sẽ xoá ${lost.length} file khỏi production`,
    '',
    `Những path này CÓ trên \`${head}\` nhưng KHÔNG có trên \`${base}\`, và lịch sử`,
    `\`${base}\` không cho thấy chúng từng bị xoá có chủ đích. Merge PR này sẽ **xoá**`,
    'chúng, và không suite nào đỏ vì việc đó — xoá một bài test không bao giờ làm',
    'test thất bại.',
    '',
    ...lost.map((r) => `- \`${r.path}\` — ${r.reason}`),
    '',
    '### Cách gỡ',
    '',
    `Cherry-pick chúng về \`${base}\` trước (đường đã dùng ở #2908), rồi đẩy lại nhánh này.`
  )
  writeSummary(summary)

  console.error(
    `::error title=Promote sẽ xoá ${lost.length} file chỉ ${head} có::${lost.map((r) => r.path).join(' ')}`
  )
  return 1
}

function writeSummary(lines) {
  if (lines.length === 0 || !process.env.GITHUB_STEP_SUMMARY) {
    return
  }
  execFileSync('sh', ['-c', `cat >> "$GITHUB_STEP_SUMMARY"`], { input: `${lines.join('\n')}\n` })
}

if (import.meta.url === `file://${process.argv[1]}`) {
  process.exit(main())
}
