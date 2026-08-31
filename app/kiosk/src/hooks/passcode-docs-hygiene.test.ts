import { describe, it, expect } from 'vitest';
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Rào cho #36 phần 2.
 *
 * Passcode mặc định + master đã bị gỡ khỏi code ở #34, và `use-passcode.test.ts`
 * đã ghim rằng chúng bị TỪ CHỐI. Nhưng lỗ hổng thật kéo dài thêm ba tháng sau
 * bản vá lại nằm ở chỗ khác: hai tài liệu thiết kế vẫn in ra con số cụ thể kèm
 * chữ "Mặc định". Không exploit được — nhưng người đọc kế tiếp không phân biệt
 * được "tài liệu tả code hiện tại" với "tài liệu tả code đã chết", nên thứ họ
 * mang đi là con số.
 *
 * Vì vậy rào này quét `docs/`, không quét `src/`: chỗ nguy hiểm còn lại là văn
 * bản, không phải code. Test của code thì cố ý được miễn — nó PHẢI nhắc tới các
 * chuỗi đó để chứng minh chúng không còn tác dụng.
 */
const RETIRED_PASSCODES = ['88888888', '12345678'];

function markdownFilesUnder(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      out.push(...markdownFilesUnder(full));
    } else if (entry.endsWith('.md')) {
      out.push(full);
    }
  }
  return out;
}

describe('passcode docs hygiene (#36)', () => {
  it('không tài liệu nào còn in ra passcode đã khai tử', () => {
    const offenders: string[] = [];

    for (const file of markdownFilesUnder('docs')) {
      const lines = readFileSync(file, 'utf-8').split('\n');
      lines.forEach((line, i) => {
        for (const code of RETIRED_PASSCODES) {
          if (line.includes(code)) {
            offenders.push(`${file}:${i + 1}: ${line.trim()}`);
          }
        }
      });
    }

    expect(
      offenders,
      `Tài liệu in lại passcode đã gỡ ở #34. Thay bằng "<đã gỡ — #34>":\n${offenders.join('\n')}`,
    ).toEqual([]);
  });

  it('không dựng lại hai tài liệu lịch sử đã bị xoá khi nhập monorepo', () => {
    const retiredDocs = [
      'docs/superpowers/plans/2026-05-04-settings-passcode.md',
      'docs/superpowers/specs/2026-05-04-settings-passcode-design.md',
    ];

    expect(retiredDocs.filter(existsSync), 'tài liệu passcode lịch sử đã bị dựng lại').toEqual([]);
  });
});
