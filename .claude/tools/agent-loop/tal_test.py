#!/usr/bin/env python3
"""Test cho bốn bản sửa của #1342 trong `tal`.

Chạy: `python3 .claude/tools/agent-loop/tal_test.py`   (không cần pytest, không cần mạng)

Vì sao có file này: `tal` trước nay **không có test nào**, và cả bốn lỗi của #1342 đều
thuộc loại *lệnh vẫn exit 0, chỉ là không làm gì* — đúng loại mà chỉ test mới bắt được,
không phải đọc code. Test ở đây gọi thẳng hàm thật, chỉ thay `gh()` bằng bản ghi lại lời
gọi, nên nó kiểm hành vi chứ không kiểm lại chính nó.

Không đụng mạng, không đụng GitHub, không cần lease.
"""

import argparse
import importlib.machinery   # `import importlib.util` KHÔNG kéo theo cái này (3.14 → AttributeError)
import importlib.util
import json
import os
import re
import sys
import tempfile
import types
from pathlib import Path

HERE = Path(__file__).resolve().parent


def load_tal():
    spec = importlib.util.spec_from_loader("tal", importlib.machinery.SourceFileLoader("tal", str(HERE / "tal")))
    mod = importlib.util.module_from_spec(spec)
    sys.modules["tal"] = mod
    spec.loader.exec_module(mod)
    return mod


tal = load_tal()

# Bản gốc của những hàm bị test monkeypatch. Không có cái này thì một test thay
# `tal.lease_file` rồi bỏ đó sẽ làm test SAU đọc nhầm — và nó đã xảy ra: test guard
# của #1382 đỏ oan vì đọc phải thẻ lease do test #1342 để lại, trong khi guard hoàn
# toàn đúng khi chạy riêng. Test rò trạng thái thì không kiểm cái nó tưởng.
#
# Đây từng là DANH SÁCH TAY, và danh sách tay thì tụt lại: `do_assert`, `lease_expired`,
# `refs_all`, `docs_gate`… bị test thay mà không có trong danh sách, nên không được
# khôi phục. Hậu quả tệ hơn "đỏ oan" một bậc — nó XANH oan: test #1751 khẳng định
# `assert` cho cùng kết quả ở gốc worktree và trong submodule vẫn "ok" ngay cả khi
# đã GỠ bản sửa, vì `tal.do_assert` lúc đó là con rối của test trước trả về hằng số.
# Một phép đo xanh mà không đo gì là loại rào tệ hơn không có rào. Nên giờ chụp TOÀN
# BỘ hàm cấp module: thêm test mới không cần nhớ cập nhật danh sách nào.
REAL = {name: obj for name, obj in vars(tal).items()
        if isinstance(obj, types.FunctionType) or name == "C"}


def restore_tal():
    for name, fn in REAL.items():
        setattr(tal, name, fn)


FAILURES: list[str] = []


def check(cond: bool, label: str, detail: str = ""):
    if cond:
        print(f"  ok   {label}")
    else:
        print(f"  FAIL {label}" + (f"\n       {detail}" if detail else ""))
        FAILURES.append(label)


# ─────────────────────────────────────────────────────────────────────────────
# Lỗi #1335/#1348 — thân PR phải được cập nhật, và `Refs` cố ý không bị đè
# ─────────────────────────────────────────────────────────────────────────────

def test_with_issue_ref():
    print("with_issue_ref (chèn Closes chỉ khi thân PR chưa nhắc issue)")

    body = "Sửa cái này."
    out = tal.with_issue_ref(body, [42], "Closes #42")
    check(out.endswith("Closes #42"), "thân PR không nhắc issue → chèn Closes", out)

    # Một PR làm xong MỘT PHẦN cố ý dùng Refs để merge không đóng issue còn dở.
    body = "Làm T3.1 thôi.\n\nRefs #42 — phần còn lại chưa làm."
    out = tal.with_issue_ref(body, [42], "Closes #42")
    check("Closes #42" not in out, "thân PR đã có `Refs #42` → KHÔNG chèn Closes", out)

    body = "Đã xong.\n\nCloses #42"
    out = tal.with_issue_ref(body, [42], "Closes #42")
    check(out.count("Closes #42") == 1, "không chèn trùng khi đã có Closes", out)

    # Nhóm nhiều issue: nhắc một cái là đủ để không bị đè.
    body = "Refs #7"
    out = tal.with_issue_ref(body, [7, 8], "Closes #7\nCloses #8")
    check("Closes" not in out, "nhóm: nhắc một issue là đủ, không chèn thêm", out)

    # #420 không được tính là #42.
    body = "Refs #420"
    out = tal.with_issue_ref(body, [42], "Closes #42")
    check(out.endswith("Closes #42"), "`#420` KHÔNG khớp `#42` (ranh giới từ)", out)


# ─────────────────────────────────────────────────────────────────────────────
# Lỗi 3 — hai bộ đếm tách nhau, và sha đi vào marker
# ─────────────────────────────────────────────────────────────────────────────

def test_verdict_counters():
    print("cmd_review_verdict (round tăng ở MỌI verdict, changes đếm riêng)")

    calls: list[list[str]] = []
    ledger: dict = {"issue": 99, "group": [99], "state": "review", "review_rounds": 0,
                    "attempts": 1, "reaps": 0, "history": []}
    written: list[dict] = []

    def fake_gh(args, check=True, stdin=None):
        calls.append(args)

        class R:
            stdout = ""
            returncode = 0
        return R()

    def fake_gh_json(args, default=None):
        # #2153: verdict giờ đọc state + sha trong MỘT lần hỏi.
        if "state,mergedAt,headRefOid" in args:
            return {"state": "OPEN", "mergedAt": None, "headRefOid": "abc123def456789"}
        return default

    tal.gh = fake_gh
    tal.gh_json = fake_gh_json
    tal.pr_issue = lambda pr: 99
    tal.ledger_read = lambda issue: (json.loads(json.dumps(ledger)), 1, tal.now())
    tal.ledger_write = lambda led, cid, note: written.append(json.loads(json.dumps(led)))
    tal.set_state_labels = lambda *a, **k: None
    tal.session_id = lambda: "sess1234abcd"
    # #2300 D1: verdict đòi đang giữ lease pr-<N> — ref kiểu cũ (commit, không payload)
    tal.refs_all_full = lambda: [{"key": "pr-500", "sha": "x", "type": "commit"}]

    class A:
        pr = 500
        verdict = "pass"
        body = "đạt"
        body_file = None

    tal.cmd_review_verdict(A())

    marker = next((c[-1] for c in calls if c[:2] == ["pr", "comment"]), "")
    check("round=1" in marker, "verdict PASS đầu tiên → round=1 trong marker", marker[:120])
    check("changes=0" in marker, "PASS không tăng bộ đếm changes", marker[:120])
    check("sha=abc123def456" in marker, "marker mang sha của bản đã review", marker[:120])
    check(written and written[-1].get("reviews_total") == 1,
          "ledger ghi reviews_total=1", str(written[-1] if written else None)[:160])
    check(written and written[-1].get("reviewed_head_sha") == "abc123def456789",
          "ledger ghi reviewed_head_sha")

    # Bản thứ hai của cùng PR, lại pass: round PHẢI thành 2 (đây là lỗi cũ).
    ledger.update(written[-1])
    calls.clear()
    written.clear()
    tal.ledger_read = lambda issue: (json.loads(json.dumps(ledger)), 1, tal.now())
    tal.cmd_review_verdict(A())
    marker = next((c[-1] for c in calls if c[:2] == ["pr", "comment"]), "")
    check("round=2" in marker, "PASS lần hai → round=2 (trước đây kẹt ở 1)", marker[:120])
    check("changes=0" in marker, "vẫn không tăng bộ đếm changes", marker[:120])


# ─────────────────────────────────────────────────────────────────────────────
# #1616 — review-claim chạy được, và lỗi giữa chừng thì NHẢ khoá
# ─────────────────────────────────────────────────────────────────────────────

def test_review_claim_runs_and_releases_on_error():
    """Hai thứ mà test cũ không chạm: đường THÀNH CÔNG của `cmd_review_claim`,
    và cái xảy ra khi có lỗi SAU khi đã giành khoá.

    Bộ test cũ chỉ gọi `assert_not_own_work` (rào tách vai) rồi dừng, nên một
    `NameError` ở ngay dòng sau cái rào sống sót qua toàn bộ suite: **mọi** lần
    gọi `review-claim` đều chết, và mỗi lần để lại `refs/tempo/leases/pr-<N>`
    không có chủ — `tal status` in session `?` cho tới hết TTL, hai session sau
    nhận `BUSY` thay vì nhận lỗi thật.
    """
    print("cmd_review_claim (#1616: chạy được + lỗi giữa chừng thì nhả khoá)")

    ledger = {"issue": 77, "group": [77], "state": "review", "review_rounds": 1,
              "sub_prs": {}, "history": []}
    notes: list[str] = []
    refs_made: list[str] = []
    refs_deleted: list[str] = []
    unlocks: list[str] = []

    def wire(gh_impl):
        tal.pr_issue = lambda pr: 77
        tal.ledger_read = lambda issue: (json.loads(json.dumps(ledger)), 1, tal.now())
        tal.ledger_write = lambda led, cid, note: notes.append(note)
        tal.local_lock = lambda key: True
        tal.local_unlock = lambda key: unlocks.append(key)
        # #2153: claim giờ đọc state PR trước; #2172: ref_create nhận payload chủ lease.
        tal.gh_json = lambda args, default=None: {"state": "OPEN"}
        tal.ref_create = lambda key, sha, payload=None: (refs_made.append(key), True)[1]
        tal.ref_delete = lambda key: refs_deleted.append(key)
        tal.head_sha = lambda: "deadbeef0000"
        tal.session_id = lambda: "revw9999zzzz"
        tal.gh = gh_impl

    def gh_ok(args, check=True, stdin=None):
        class R:
            stdout = "backend/a.php\nbackend/b.php"
            returncode = 0
        return R()

    class A:
        pr = 501
        json = False
        allow_self = False

    wire(gh_ok)
    out = tal.cmd_review_claim(A())

    check(out["pr"] == 501 and out["issue"] == 77, "trả về đúng PR + issue", str(out)[:120])
    check(out["files"] == ["backend/a.php", "backend/b.php"], "liệt kê file của PR", str(out.get("files")))
    check(refs_made == ["pr-501"], "có giành ref khoá review", str(refs_made))
    # Đây là assertion bắt lỗi gốc: dòng ledger nêu session, và trước bản vá nó
    # tham chiếu một biến CỤC BỘ CỦA HÀM KHÁC (`mine`) → NameError trước khi tới đây.
    check(notes and "revw9999" in notes[-1], "ghi ledger nêu session review", str(notes)[:160])
    check(refs_deleted == [] and unlocks == [], "đường thành công KHÔNG nhả khoá",
          f"deleted={refs_deleted} unlocks={unlocks}")

    # Lỗi SAU khi giành khoá — khoá phải được trả lại, không để lại vết không chủ.
    refs_made.clear()
    refs_deleted.clear()
    unlocks.clear()
    notes.clear()

    def gh_boom(args, check=True, stdin=None):
        raise RuntimeError("gh chết giữa chừng")

    wire(gh_boom)
    try:
        tal.cmd_review_claim(A())
        raised = False
    except RuntimeError:
        raised = True

    check(raised, "lỗi vẫn nổi lên, không bị nuốt")
    check(refs_deleted == ["pr-501"], "lỗi giữa chừng → XOÁ ref khoá", str(refs_deleted))
    check(unlocks == ["pr-501"], "lỗi giữa chừng → nhả cả khoá cục bộ", str(unlocks))

    restore_tal()


# ─────────────────────────────────────────────────────────────────────────────
# Lỗi 4 — thẻ lease được ĐÁNH DẤU released, không bị xoá
# ─────────────────────────────────────────────────────────────────────────────

def test_released_lease_card():
    print("mark_lease_released + lease_file + do_assert")

    with tempfile.TemporaryDirectory() as d:
        wt = Path(d) / "issue-77"
        wt.mkdir()
        card = wt / tal.LEASE_FILE
        card.write_text(json.dumps({"issue": 77, "session": "s1", "epoch": 3, "keys": ["issue-77"]}))

        tal.mark_lease_released(wt)
        after = json.loads(card.read_text())

        check(card.is_file(), "thẻ vẫn còn trên đĩa (không bị xoá)")
        check(after.get("released") is True, "thẻ được đánh dấu released", str(after))
        check("epoch" not in after, "epoch bị bỏ — fencing token cũ không còn giá trị", str(after))
        check(bool(after.get("released_at")), "có mốc thời gian released_at")

        # lease_file đọc thẳng đường dẫn vẫn TRẢ VỀ thẻ — hook cần thấy nó.
        _, lf = tal.lease_file(wt, search=False)
        check(lf.get("released") is True, "lease_file(search=False) vẫn trả thẻ đã released")

        # do_assert phải từ chối rõ ràng, không được đi tiếp.
        tal.lease_file = lambda start=None, search=True: (wt, lf)
        try:
            tal.do_assert(quiet=True)
            check(False, "do_assert trên thẻ đã released phải Fail")
        except tal.Fail as e:
            check("LEASE ĐÃ NHẢ" in str(e), "do_assert báo đúng lý do", str(e)[:140])
            check("tal claim 77" in str(e), "thông điệp chỉ đúng lệnh cần chạy", str(e)[:140])


# ─────────────────────────────────────────────────────────────────────────────
# Lỗi #1355 — cổng merge phải loại trừ tương hỗ, cây tạm phải riêng từng session
# ─────────────────────────────────────────────────────────────────────────────


def test_merge_batch_releases_gate_when_window_raises():
    """#1617 — cổng merge KHÔNG được kẹt khi có lỗi giữa lúc đã giành nó.

    `batch_gate_acquire()` giành cổng, nhưng `try/finally` duy nhất bắt đầu ~64
    dòng sau đó. Trong khoảng giữa có `git worktree add` (không `check=False`),
    `merge_sub_prs`, `realign_pointers`, `batch_mark_alive` — mọi thứ đều raise
    được, và chỉ `Fail` mới được bắt.

    Rò ở đây nặng hơn #1616: cổng là bước TUẦN TỰ TOÀN REPO, nên một khoá kẹt
    chặn MỌI session merge cho tới hết TTL, không chỉ một PR.
    """
    print("cmd_merge_batch (#1617: lỗi trong cửa sổ đã-giành-cổng thì vẫn nhả cổng)")

    released: list[int] = []

    def wire(sub_impl):
        tal.gh_json = lambda args, default=None: [
            {"number": 900, "title": "t", "headRefName": "issue-900",
             "isDraft": False, "headRefOid": "cafe0000"}
        ]
        tal.merge_blockers = lambda pr, require_ci=False: (900, None, (None, None))
        tal.refs_all = lambda: []          # #2153: lô giờ né PR có review lease sống
        tal.batch_gate_acquire = lambda: True
        tal.batch_gate_release = lambda: released.append(1)
        tal.merge_sub_prs = sub_impl

    class A:
        limit = 0
        dry_run = False
        skip_suite = True
        suite = False
        json = False

    # ── lỗi KHÔNG phải Fail: chỗ gọi chỉ bắt Fail, nên nó lọt qua cả hàm.
    wire(lambda issue: (_ for _ in ()).throw(RuntimeError("worktree add chết")))
    try:
        tal.cmd_merge_batch(A())
        raised = False
    except RuntimeError:
        raised = True

    check(raised, "lỗi vẫn nổi lên, không bị nuốt")
    check(released == [1], "lỗi trong cửa sổ → NHẢ cổng đúng một lần", str(released))

    # ── lối ra `if not lot:` (mọi PR đều hỏng cụm repo con) cũng phải nhả, và
    #    phải nhả ĐÚNG MỘT LẦN — trước bản vá nó có một `batch_gate_release()`
    #    thủ công, để nguyên cùng lớp bọc mới sẽ thành nhả hai lần.
    released.clear()
    wire(lambda issue: (_ for _ in ()).throw(tal.Fail("cụm repo con chưa xong", 2)))
    try:
        tal.cmd_merge_batch(A())
        failed = False
    except tal.Fail:
        failed = True

    check(failed, "lô rỗng sau khi lọc → vẫn Fail như cũ")
    check(released == [1], "lối ra 'lô rỗng' nhả cổng ĐÚNG MỘT LẦN", str(released))

    restore_tal()


def test_batch_gate():
    print("cổng merge: khoá loại trừ + cây tạm theo session (#1355)")

    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        (root / ".claude" / "worktrees").mkdir(parents=True)
        state = root / "state"; state.mkdir()

        class FakeCtx:
            repo = "o/r"
            main_worktree = root
            worktrees_dir = root / ".claude" / "worktrees"
            state_dir = state
        tal.C = FakeCtx()

        # Cây tạm phải mang session vào tên — đó là thứ khiến hai session không
        # còn xoá cây của nhau.
        tal.session_id = lambda: "AAAAAAAA-1111"
        a_dir = tal.batch_tmp_dir()
        tal.session_id = lambda: "BBBBBBBB-2222"
        b_dir = tal.batch_tmp_dir()
        check(a_dir != b_dir, "hai session → hai cây tạm khác nhau", f"{a_dir.name} vs {b_dir.name}")
        check(a_dir.name.startswith("_batch-"), "vẫn theo tiền tố _batch-", a_dir.name)

        # Khoá: session A giành được, session B bị từ chối (không chờ, không xoá gì).
        refs: set[str] = set()
        tal.head_sha = lambda: "0" * 40
        tal.ref_create = lambda k, sha, payload=None: (k not in refs) and (refs.add(k) or True)
        tal.ref_exists = lambda k: k in refs
        tal.ref_delete = lambda k: refs.discard(k)

        tal.session_id = lambda: "AAAAAAAA-1111"
        check(tal.batch_gate_acquire() is True, "session A giành được cổng")
        tal.session_id = lambda: "BBBBBBBB-2222"
        check(tal.batch_gate_acquire() is False, "session B BỊ TỪ CHỐI khi A đang giữ")
        check(b_dir.exists() is False, "B không hề đụng tới cây của A")

        # Nhả rồi thì người sau vào được — không cần can thiệp tay.
        tal.session_id = lambda: "AAAAAAAA-1111"
        tal.batch_gate_release()
        tal.session_id = lambda: "BBBBBBBB-2222"
        check(tal.batch_gate_acquire() is True, "A nhả xong thì B vào được")
        tal.batch_gate_release()

        # Cổng rò: ref còn nhưng khoá cục bộ đã mất ⇒ gc phải thu hồi.
        refs.add(tal.BATCH_KEY)
        acts = tal.reap_batch_gate(dry=False)
        check(tal.BATCH_KEY not in refs, "gc thu hồi cổng bị rò (khoá cục bộ không còn)")
        check(any("rò" in x["action"] for x in acts), "gc nói rõ vì sao thu hồi", str(acts))

        # #1468/A — cây tạm chỉ bị dọn khi CHỨNG MINH ĐƯỢC là chết.
        #
        # Bản trước xoá thẳng mọi cây `_batch*` không phải của mình. Nhưng
        # `_batch-<session-khác>` đang chạy full suite KHÔNG phải mồ côi: nó bị
        # `rm -rf` giữa chừng, pest chết ở bootstrap với `Class "Pest\Panic" not
        # found`, và cổng kết luận "full suite ĐỎ" — tái hiện 2/2 lần (#1436).
        tal.run = lambda *a, **k: None
        import os as _os, time as _time

        # (a) cây của session khác, pid CÒN SỐNG → phải để nguyên
        alive = FakeCtx.worktrees_dir / "_batch-ALIVE111"
        alive.mkdir()
        (alive / tal.BATCH_MARK).write_text(json.dumps({
            "pid": _os.getpid(), "session": "ALIVE111", "host": tal.socket.gethostname(),
        }))

        # (b) cây của session đã chết (pid không tồn tại) → phải dọn
        dead = FakeCtx.worktrees_dir / "_batch-DEAD2222"
        dead.mkdir()
        (dead / tal.BATCH_MARK).write_text(json.dumps({
            "pid": 999_999_999, "session": "DEAD2222", "host": tal.socket.gethostname(),
        }))

        # (c) `_batch` cũ trước #1355: không có dấu, và đã cũ → dọn
        legacy = FakeCtx.worktrees_dir / "_batch"
        legacy.mkdir()
        _os.utime(legacy, (_time.time() - 3600, _time.time() - 3600))

        # (d) cây vừa tạo, chưa kịp ghi dấu → KHÔNG được dọn
        fresh = FakeCtx.worktrees_dir / "_batch-FRESH333"
        fresh.mkdir()

        mine = tal.batch_tmp_dir(); mine.mkdir(exist_ok=True)
        tal.reap_batch_gate(dry=False)

        check(alive.exists(),
              "KHÔNG dọn cây của session khác còn sống",
              "dọn nó là giết full suite của người ta rồi báo nhầm thành test đỏ")
        check(not dead.exists(), "dọn cây của session đã chết (pid không còn)")
        check(not legacy.exists(), "dọn cây `_batch` cũ trước #1355 (không dấu, đã cũ)")
        check(fresh.exists(),
              "KHÔNG dọn cây vừa tạo chưa kịp ghi dấu",
              "cửa sổ giữa `worktree add` và `batch_mark_alive` là vài mili-giây, đủ để mất cả lô")
        check(mine.exists(), "không tự dọn cây của chính mình")
        check(mine.exists(), "KHÔNG đụng cây tạm của chính session đang chạy")


# ─────────────────────────────────────────────────────────────────────────────
# Lỗi #1384 — issue ĐÃ SHIP không được quay lại hàng đợi
# ─────────────────────────────────────────────────────────────────────────────

def test_queue_skips_shipped():
    print("queue_skip_reason (nhãn nào thì KHÔNG nhặt)")

    R, S = tal.L_READY, tal.L_SHIPPED

    # Cái đã ship. Nó ở lại OPEN kèm `agent:ready` vì `Closes #N` không đóng
    # được issue khi PR merge vào `dev` (GitHub chỉ auto-close ở default branch,
    # là `main`) — nên hàng đợi phải tự loại, không trông vào issue đóng.
    why = tal.queue_skip_reason({R, S}, has_lease=False)
    check(why is not None, "issue mang status:shipped KHÔNG được nhặt", str(why))
    check(why and "ship" in why.lower(), "lý do đọc được, nói rõ là đã ship", str(why))

    # Vẫn phải nhặt được cái bình thường — nếu không thì bản sửa này chỉ là
    # làm hàng đợi rỗng, và test trên sẽ pass một cách vô nghĩa.
    check(tal.queue_skip_reason({R}, has_lease=False) is None,
          "issue ready thường vẫn nhặt được")

    # shipped THẮNG cả những nhãn còn sót lại của vòng đời trước.
    for leftover in (tal.L_CHANGES, tal.L_PASSED, tal.L_AWAIT):
        why = tal.queue_skip_reason({R, S, leftover}, has_lease=False)
        check(why is not None and "ship" in why.lower(),
              f"shipped thắng nhãn sót lại {leftover}", str(why))

    # Các nhánh cũ không được rơi mất khi tách hàm.
    check(tal.queue_skip_reason(set(), has_lease=False) is not None,
          "thiếu agent:ready → vẫn bị loại")
    check(tal.queue_skip_reason({R, tal.L_BLOCKED}, has_lease=False) is not None,
          "blocked → vẫn bị loại")
    check(tal.queue_skip_reason({R, tal.L_DEAD}, has_lease=False) is not None,
          "dead-letter → vẫn bị loại")
    check(tal.queue_skip_reason({R}, has_lease=True) is not None,
          "đang có lease → vẫn bị loại")


def test_gc_labels_shipped_without_worktree():
    print("cmd_gc (đối chiếu nhãn shipped KHÔNG phụ thuộc worktree cục bộ)")

    labelled: list[tuple] = []

    with tempfile.TemporaryDirectory() as d:
        empty = Path(d) / "worktrees"          # KHÔNG có worktree nào — đúng tình huống
        empty.mkdir()                          # máy đã dọn, hoặc máy khác merge.

        class FakeCtx:
            repo = "o/r"
            worktrees_dir = empty
            main_worktree = Path(d)
            state_dir = Path(d) / "state"
        FakeCtx.state_dir.mkdir()

        def fake_gh_json(args, default=None):
            if args[:2] == ["pr", "list"] and "merged" in args:
                return [{"number": 900, "headRefName": "issue-77", "closingIssuesReferences": []}]
            if args[:2] == ["pr", "list"]:
                return []
            return default

        tal.C = FakeCtx
        tal.gh_json = fake_gh_json
        tal.reap_batch_gate = lambda dry: []
        tal.reap_leases = lambda dry: []
        tal.delete_merged_branches = lambda repo, dry, protect: []
        tal.submodules = lambda: {}
        tal.branch_exists_local = lambda b: False
        tal.branch_exists_remote = lambda b: False
        tal.open_issues = lambda: [{"number": 77, "title": "x", "labels": [{"name": tal.L_READY}], "body": ""}]
        tal.ledger_read = lambda issue: ({"issue": issue, "group": [issue]}, 1, tal.now())
        tal.ledger_write = lambda led, cid, note: None
        tal.issue_data = lambda n: {"state": "open"}
        tal.set_state_labels = lambda n, want: labelled.append((n, tuple(sorted(want))))
        tal.gh = lambda args, check=True, stdin=None: None
        tal.run = lambda *a, **k: type("R", (), {"stdout": "", "returncode": 0})()
        # #2300: refs giờ đo qua đường strict (API hỏng ⇒ raise) — test này mô tả
        # "không có lease nào" nên phải stub tường minh, không dựa vào default=[].
        tal.refs_all_full = lambda: []

        class A:
            dry_run = False
            no_submodules = True
            include_abandoned = False
            close = False
            json = False

        tal.cmd_gc(A())

    check((77, (tal.L_SHIPPED,)) in labelled,
          "issue của PR đã merge được gắn status:shipped dù máy này KHÔNG có worktree",
          str(labelled))

    # Vì sao test này tồn tại: phần gắn nhãn từng nằm SAU một `continue` xét
    # worktree/branch cục bộ — một trạng thái theo MÁY. Nên cùng một lô merge
    # cho ra kết quả khác nhau tuỳ máy nào chạy gc, và issue không nhãn thì rơi
    # về hàng đợi như việc mới (#1353/#1356 đã đi đúng đường đó).


# ─────────────────────────────────────────────────────────────────────────────
# Lỗi #1382 — rào lease không được NHỐT session: chặn ghi, không chặn chỗ đứng
# ─────────────────────────────────────────────────────────────────────────────

def test_hook_guard_not_a_trap():
    print("cmd_hook_guard: chặn GHI, không chặn chỗ đứng (#1382)")

    import contextlib
    import io

    MINE = "d4318e2a-mine"
    OTHER = "ffffffff-other"

    with tempfile.TemporaryDirectory() as td:
        wt = Path(td) / "issue-99"
        wt.mkdir()

        def card(**extra):
            (wt / tal.LEASE_FILE).write_text(json.dumps({
                "repo": "o/r", "issue": 99, "group": [99], "branch": "issue-99",
                "session": MINE, "keys": ["issue-99"], **extra,
            }))

        def guard(tool, ti, sid, cwd=str(wt)) -> bool:
            """True = BỊ CHẶN."""
            payload = json.dumps({"tool_name": tool, "tool_input": ti,
                                  "session_id": sid, "cwd": cwd})
            out, old = io.StringIO(), sys.stdin
            sys.stdin = io.StringIO(payload)
            try:
                with contextlib.redirect_stdout(out):
                    try:
                        tal.cmd_hook_guard(None)
                    except SystemExit:
                        pass
            finally:
                sys.stdin = old
            return "deny" in out.getvalue()

        def bash(cmd, sid=MINE):
            return guard("Bash", {"command": cmd}, sid)

        card(released=True, released_at="2026-07-30T23:24:45Z")

        # KHÔNG được nhốt: đọc và đi ra ngoài phải chạy được.
        check(not bash("pwd"), "`pwd` trong worktree đã nhả lease → CHO QUA")
        check(not bash("cd /tmp && ls"), "`cd` ra ngoài → CHO QUA (không nhốt)")
        for cmd in ("git status --porcelain", "git log --oneline -5", "git diff",
                    "git show HEAD", "git fetch origin dev", "git rev-parse HEAD",
                    "git merge-base HEAD dev", "git ls-files", "git ls-remote origin",
                    "git describe", "git blame file", "git cat-file -t HEAD",
                    "git for-each-ref", "git remote -v", "git branch --list",
                    "git worktree list", "git stash list", "git config --get user.name"):
            check(not bash(cmd), f"lệnh git chỉ đọc → CHO QUA: {cmd}")
        check(not bash("git -C /tmp status"), "git -C ngoài worktree, chỉ đọc → CHO QUA")
        check(not bash("git -C /tmp commit -m x"), "git -C ngoài worktree, ghi ngoài → CHO QUA")
        check(not bash("echo hi > /tmp/tal-hook-out.txt"), "redirect ra /tmp → CHO QUA")
        check(not bash("rm -rf /tmp/tal-hook-junk"), "rm đích /tmp → CHO QUA")
        check(not bash("sed -i s/a/b/ /tmp/tal-hook-file"), "sed -i đích /tmp → CHO QUA")
        check(not bash("sed -n '1p' file 2>/dev/null"), "sed đọc + 2>/dev/null → CHO QUA")
        # Lối thoát mà chính thông điệp deny quảng cáo phải thật sự đi được.
        check(not bash("tal claim 99"), "`tal claim` → CHO QUA (đúng lối thoát nó mách)")
        check(not bash(".claude/tools/agent-loop/tal status"), "`tal status` → CHO QUA")

        # Nới rào không được biến thành bỏ rào.
        check(bash("git commit -m x"), "`git commit` → CHẶN (worktree đã nhả lease)")
        check(bash("echo hong > f"), "chuyển hướng `>` → CHẶN")
        check(bash("rm -rf src"), "`rm -rf` → CHẶN")
        check(bash("sed -i s/a/b/ f.php"), "`sed -i` trong worktree → CHẶN")
        check(bash(f'''python3 -c 'open("{wt / "pwned.txt"}","w").write("x")' ''',
                   sid=OTHER), "Python ghi literal vào worktree session khác → CHẶN")
        check(guard("Bash", {"command": f'''node -e 'require("fs").writeFileSync("{wt / "n.txt"}","x")' '''},
                    OTHER, cwd="/tmp"),
              "Node đứng ngoài nhưng ghi literal vào worktree session khác → CHẶN")
        check(guard("Bash", {"command": f'''ruby -e 'File.write("{wt / "r.txt"}","x")' '''},
                    OTHER, cwd="/tmp"),
              "Ruby đứng ngoài nhưng ghi literal vào worktree session khác → CHẶN")
        check(guard("Edit", {"file_path": str(wt / "a.txt")}, MINE, cwd="/tmp"),
              "Edit vào worktree đã nhả lease → CHẶN (công cụ ghi vẫn xét đường dẫn)")

        # Session khác: cùng luật — đọc thì cho, ghi thì chặn.
        card(epoch=1)
        check(not bash("pwd", sid=OTHER), "session khác `pwd` → CHO QUA")
        check(bash("git commit -m x", sid=OTHER), "session khác `git commit` → CHẶN")

        # Rào theo MẪU LỆNH giữ nguyên: xét mọi lệnh Bash, kể cả của chủ lease.
        check(bash("git checkout -b hotfix-abc"), "tên branch sai → CHẶN (rào mẫu lệnh không đổi)")
        check(not bash("git checkout -b issue-99"), "tên `issue-99` → CHO QUA")


# ─────────────────────────────────────────────────────────────────────────────
# Lỗi #1353 — pointer submodule: tới-được, không phải bằng-nhau
# ─────────────────────────────────────────────────────────────────────────────

def test_pointer_verdict():
    """#1353 — pointer submodule của PR so với pointer của base.

    Đồ thị dùng chung cho mọi ca (chữ = commit trong repo con):

        F ── B          F = pointer tại điểm rẽ nhánh, B = dev đã đi tiếp
         └── X          X = nhánh issue đi hướng khác  ⇒ B và X PHÂN KỲ
        B ── C          C = hậu duệ của B
    """
    print("pointer_verdict (#1353)")

    anc = {("F", "B"), ("F", "X"), ("F", "C"), ("B", "C")}

    def is_ancestor(a: str, b: str) -> bool:
        return a == b or (a, b) in anc

    v = tal.pointer_verdict

    check(v("F", "B", "F", is_ancestor) == "untouched",
          "PR không đụng pointer → untouched, dù dev đã đi tiếp",
          "đây là ca hay bị báo oan nhất: branch cũ, dev chạy trước")
    check(v("F", "B", "B", is_ancestor) == "same", "bump tới đúng chỗ dev đang đứng → same")
    check(v("F", "B", "C", is_ancestor) == "descends", "bump tiến lên từ dev → descends (fast-forward)")
    check(v("F", "C", "B", is_ancestor) == "behind", "pointer sau dev nhưng vẫn tới được → behind, KHÔNG chặn")
    check(v("F", "B", "X", is_ancestor) == "diverged", "hai nhánh rẽ khác hướng → diverged (ca #1318)")
    check(v("F", None, "X", is_ancestor) == "skip", "không đọc được pointer base → skip, không đoán")
    check(v(None, "B", "C", is_ancestor) == "descends", "không có điểm rẽ vẫn kết luận được bằng base")

    # `behind` phải được cho qua: sau khi merge cả cụm, pointer umbrella là tổ tiên
    # của tip repo con. Chặn nó là dựng lại đúng cái chặn oan mà #1353 gỡ ở pre-push.
    check(v("F", "C", "B", is_ancestor) != "diverged",
          "merge cả cụm xong (pointer thành tổ tiên) KHÔNG bị coi là phân kỳ")


# ─────────────────────────────────────────────────────────────────────────────
# Lỗi #1385 — gc không được xoá worktree/branch/lease của session đang làm
# ─────────────────────────────────────────────────────────────────────────────

def test_gc_spares_live_lease():
    """#1385 — issue được claim LẠI sau khi PR trước đã merge.

    Đó là ca `Refs #N` (PR ship một phần, issue còn dở) mà tal cố ý hỗ trợ. Trước
    bản sửa, `gc` của session khác xoá worktree + branch + `led["lease"]` của session
    đang làm, chỉ vì `headRefName` của một PR đã merge trùng `issue-<N>`.
    """
    print("gc: chừa issue đang có lease sống (#1385)")

    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        (root / ".claude" / "worktrees").mkdir(parents=True)
        state = root / "state"; state.mkdir()

        class FakeCtx:
            repo = "o/r"
            main_worktree = root
            worktrees_dir = root / ".claude" / "worktrees"
            state_dir = state
        tal.C = FakeCtx()

        # #1353 = đang có lease sống; #1356 = đã xong thật, không ai giữ.
        ledgers = {
            1353: {"issue": 1353, "state": "queued", "epoch": 3, "group": [1353],
                   "lease": {"session": "OTHER-SESSION", "ttl": 2700}},
            1356: {"issue": 1356, "state": "shipped", "epoch": 1, "group": [1356],
                   "lease": None},
        }
        tal.refs_all = lambda: ["issue-1353", "issue-1356"]
        tal.ledger_read = lambda n: (ledgers[n], 1, "now")
        tal.lease_expired = lambda d, upd: False      # cả hai đều trong TTL

        live = tal.live_lease_issues()
        check(live == {1353}, "chỉ issue có lease trong sổ mới tính là đang sống", str(live))

        # `delete_merged_branches` tôn trọng `protect` — cách gc chừa branch.
        deleted: list[str] = []
        tal.gh_json = lambda args, default=None: (
            [{"number": 9, "headRefName": "issue-1353", "headRepositoryOwner": {"login": "o"}},
             {"number": 8, "headRefName": "issue-1356", "headRepositoryOwner": {"login": "o"}}]
            if "pr" in args else
            [{"ref": "refs/heads/issue-1353"}, {"ref": "refs/heads/issue-1356"}]
        )
        tal.gh = lambda args, check=True: deleted.append(args[-1]) or type("P", (), {})()

        protect = {"main", "dev", "master"} | {f"issue-{n}" for n in live}
        acts = tal.delete_merged_branches("o/r", dry=False, protect=protect)
        killed = [a["branch"] for a in acts]
        check("issue-1353" not in killed, "KHÔNG xoá branch của issue đang có lease sống")
        check("issue-1356" in killed, "vẫn dọn branch của issue không ai giữ", str(killed))


# ─────────────────────────────────────────────────────────────────────────────
# #1393 — merge CẢ CỤM: PR con trước, umbrella sau, và không merge nửa vời
# ─────────────────────────────────────────────────────────────────────────────

def test_merge_sub_prs():
    print("merge_sub_prs (PR con merge TRƯỚC umbrella, #1393)")

    calls: list[list[str]] = []
    state = {"s1": "OPEN", "s2": "OPEN"}

    def fake_gh(args, check=True, stdin=None):
        calls.append(args)

        class R:
            stdout = ""
            stderr = ""
            returncode = 0
        return R()

    def fake_gh_json(args, default=None):
        if args[:2] == ["pr", "view"]:
            pr = args[2]
            key = "s1" if pr == "11" else "s2"
            return {"state": state[key], "mergeable": "MERGEABLE"}
        return default

    tal.gh, tal.gh_json = fake_gh, fake_gh_json
    tal.ledger_read = lambda issue: ({"issue": 9, "sub_prs": {
        "pos-web": {"repo": "o/pos", "pr": 11, "branch": "issue-9"},
        "workstation-app": {"repo": "o/ws", "pr": 22, "branch": "issue-9"},
    }}, 1, tal.now())

    done = tal.merge_sub_prs(9)
    merged = [c for c in calls if c[:2] == ["pr", "merge"]]
    check(len(merged) == 2, "merge đủ CẢ HAI PR con", str(merged))
    check(all("--merge" in c and "--delete-branch" in c for c in merged),
          "dùng merge-commit + xoá branch (KHÔNG squash — squash sinh sha mới, pointer treo)")
    check([c[2] for c in merged] == ["11", "22"], "thứ tự ổn định theo path", str(merged))
    check(len(done) == 2 and all(d["action"] == "đã merge" for d in done), "báo cáo đủ hai mục")

    # PR con đã merge từ trước → bỏ qua, KHÔNG merge lại.
    calls.clear()
    state["s1"] = "MERGED"
    done = tal.merge_sub_prs(9)
    merged = [c for c in calls if c[:2] == ["pr", "merge"]]
    check(len(merged) == 1, "PR con đã merge thì không merge lại", str(merged))
    check(any(d["action"] == "đã merge từ trước" for d in done), "vẫn báo cáo nó")

    # PR con CLOSED (không merge) → phải DỪNG, tuyệt đối không merge umbrella.
    calls.clear()
    state["s1"] = "CLOSED"
    try:
        tal.merge_sub_prs(9)
        check(False, "PR con CLOSED phải Fail, không được đi tiếp")
    except tal.Fail as e:
        check("CLOSED" in str(e), "nói rõ trạng thái chặn", str(e)[:120])
        check(not [c for c in calls if c[:2] == ["pr", "merge"]],
              "KHÔNG merge PR con nào khác khi cụm đã hỏng")

    # Không có sub_prs → không làm gì, không nổ.
    tal.ledger_read = lambda issue: ({"issue": 9}, 1, tal.now())
    check(tal.merge_sub_prs(9) == [], "issue không có repo con → no-op")


# ─────────────────────────────────────────────────────────────────────────────
# #1342 — hai mục cuối: dead-letter đếm THẤT BẠI, và remove_worktree phải rmtree
# ─────────────────────────────────────────────────────────────────────────────

def test_dead_letter_counts_failures():
    print("dead-letter đếm vòng review CHƯA ĐẠT, không đếm lần claim (#1342)")

    src = (HERE / "tal").read_text()
    i = src.index("failures = led.get(\"review_rounds\", 0)")
    blk = src[i:i + 400]

    check("failures >= MAX_ATTEMPTS" in blk,
          "ngưỡng đứng trên `failures`, không phải `attempts`", blk[:120])
    check('led["attempts"] > MAX_ATTEMPTS' not in src,
          "không còn chỗ nào lấy `attempts` làm ngưỡng dead-letter")
    check('led.get("review_rounds", 0)' in blk,
          "`failures` lấy từ review_rounds — chỉ tăng khi verdict `changes`")

    # Ngưỡng phải là >=, không phải >: MAX_ATTEMPTS=3 nghĩa là ĐÚNG 3 vòng hỏng
    # thì dừng, chứ không phải 4. Sai một đơn vị ở đây là một vòng review thừa.
    check(">= MAX_ATTEMPTS" in blk and "> MAX_ATTEMPTS" not in blk.replace(">= MAX_ATTEMPTS", ""),
          "dùng `>=` — MAX_ATTEMPTS=3 là dừng ở vòng hỏng thứ 3, không phải thứ 4")


def test_remove_worktree_rmtrees():
    print("remove_worktree xoá THẬT thư mục, không chỉ bỏ đăng ký (#1349)")

    src = (HERE / "tal").read_text()
    blk = src[src.index("def remove_worktree("):]
    blk = blk[:blk.index("\ndef ", 10)]

    check("remove_orphan_tree(orphan)" in blk,
          "có xoá đã kiểm chứng — `remove --force` từ chối thì `prune` KHÔNG xoá thư mục "
          "(#2177: rmtree nhắm vào bản ĐÃ DỜI, không đục thẳng chỗ cũ)")
    check(blk.index("worktree\", \"remove\"") < blk.index("remove_orphan_tree")
          < blk.index("\"prune\""),
          "đúng thứ tự: remove → rmtree → prune")

    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        wtdir = root / ".claude" / "worktrees"
        (wtdir / "issue-5" / "vendor").mkdir(parents=True)
        (wtdir / "issue-5" / "vendor" / "big.bin").write_text("x")

        class FakeCtx:
            main_worktree = root
            worktrees_dir = wtdir
        tal.C = FakeCtx()
        tal.run = lambda *a, **k: None          # git remove/prune "thành công" mà không làm gì
        tal.branch_exists_local = lambda br: False

        tal.remove_worktree(5)
        check(not (wtdir / "issue-5").exists(),
              "thư mục biến mất kể cả khi git remove im lặng không làm gì")


# ─────────────────────────────────────────────────────────────────────────────
# #2177 — gc không được để lại worktree nửa-chết, và guard bắt worktree mồ côi
# ─────────────────────────────────────────────────────────────────────────────

def test_remove_worktree_failure_keeps_registration():
    """#2177 (a) — xoá thư mục THẤT BẠI thì KHÔNG prune.

    Trạng thái nguy hiểm nhất là nửa-chết: thư mục còn, đăng ký git mất. `cd`
    vào đó vẫn được, và mọi lệnh git từ đó im lặng giải về repo cha — 4 sự cố
    ghi nhầm cây chính trong một phiên. Nên khi không dời/xoá được thư mục,
    `remove_worktree` phải giữ nguyên đăng ký (không prune) và khai thật (False).
    """
    print("remove_worktree: không xoá được ⇒ KHÔNG prune, không nửa-chết (#2177)")

    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        wtdir = root / ".claude" / "worktrees"
        target = wtdir / "issue-7"
        (target / "vendor").mkdir(parents=True)
        (target / ".git").write_text("gitdir: /dau/do/.git/worktrees/issue-7\n")

        class FakeCtx:
            main_worktree = root
            worktrees_dir = wtdir
        tal.C = FakeCtx()

        calls: list[list[str]] = []

        def fake_run(cmd, cwd=None, check=True, stdin=None):
            calls.append(cmd)

            class R:
                returncode = 0
                stdout = ""
            return R()

        tal.run = fake_run                      # `git worktree remove` "chạy" mà không xoá gì
        tal.branch_exists_local = lambda br: False

        # (1) khoá quyền ghi thư mục cha ⇒ rename thất bại ⇒ phải giữ nguyên tất cả
        os.chmod(wtdir, 0o555)
        try:
            ok = tal.remove_worktree(7)
        finally:
            os.chmod(wtdir, 0o755)
        check(ok is False, "trả False — không khai 'đã dọn' cho việc chưa làm")
        check(not any("prune" in c for c in calls),
              "KHÔNG chạy `git worktree prune` khi thư mục chưa dời/xoá được")
        check(target.exists() and (target / ".git").exists(),
              "thư mục + .git còn nguyên vẹn — git vẫn nhận worktree, không nửa-chết")

        # (2) hết khoá ⇒ dời được ⇒ đường dẫn cũ biến mất TRƯỚC khi prune chạy
        calls.clear()
        ok = tal.remove_worktree(7)
        check(ok is True, "dời được thì trả True")
        check(not target.exists(), "đường dẫn cũ hết tồn tại — prune từ đây là an toàn")
        check(any("prune" in c for c in calls), "prune CÓ chạy sau khi đã dời xong")


def test_orphan_cleanup_clears_acl_and_verifies_deletion():
    """#2364 — Composer ACL không được làm gc báo thành công giả."""
    print("orphan cleanup: gỡ ACL macOS + đọc lại sau xoá (#2364)")

    with tempfile.TemporaryDirectory() as td:
        orphan = Path(td) / "issue-9.orphan-20260810T000000Z"
        (orphan / "backend" / "vendor").mkdir(parents=True)

        real_platform = tal.sys.platform
        real_run, real_rmtree = tal.run, tal.shutil.rmtree
        acl_cleared = False

        def fake_run(cmd, **kwargs):
            nonlocal acl_cleared
            if cmd[:2] == ["chmod", "-RN"]:
                acl_cleared = True
            return type("R", (), {"returncode": 0, "stdout": "", "stderr": ""})()

        def acl_sensitive_rmtree(path):
            if acl_cleared:
                real_rmtree(path)
            # Mô phỏng `ignore_errors=True` cũ: không ném, nhưng cũng không xoá.

        tal.sys.platform = "darwin"
        tal.run, tal.shutil.rmtree = fake_run, acl_sensitive_rmtree
        try:
            check(tal.remove_orphan_tree(orphan) is True,
                  "gỡ ACL trước ⇒ cây biến mất và mới báo thành công")

            orphan.mkdir()
            acl_cleared = False
            tal.sys.platform = "linux"
            check(tal.remove_orphan_tree(orphan) is False,
                  "rmtree im lặng bỏ sót ⇒ đọc lại thấy còn và báo THẤT BẠI")
        finally:
            tal.sys.platform = real_platform
            tal.run, tal.shutil.rmtree = real_run, real_rmtree


def test_gc_retries_leaked_orphan_shells():
    """#2364 — vỏ từ lượt cũ phải có đường hội tụ, không nằm đó vĩnh viễn."""
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        wtdir = root / ".claude" / "worktrees"
        orphan = wtdir / "issue-12.orphan-20260810T000000Z"
        orphan.mkdir(parents=True)

        class FakeCtx:
            worktrees_dir = wtdir

        real_c, real_remove = tal.C, tal.remove_orphan_tree
        tal.C = FakeCtx()
        removed = []
        tal.remove_orphan_tree = lambda path: removed.append(path) or True
        try:
            acts = tal.cleanup_orphan_worktrees()
        finally:
            tal.C, tal.remove_orphan_tree = real_c, real_remove

        check(removed == [orphan], "gc thử lại đúng vỏ orphan cũ")
        check(acts and "đã kiểm biến mất" in acts[0]["action"],
              "chỉ báo thành công sau helper đã xác nhận")


def test_worktree_attached_guard():
    """#2177 (b) — guard bắt được thư mục mồ côi dưới `.claude/worktrees/`.

    Fixture git THẬT, không mock: điều cần chứng minh là hành vi của chính
    `git rev-parse --show-toplevel` khi đứng trong một thư mục mà đăng ký
    worktree không tồn tại — nó trả về repo CHA, không báo lỗi gì.
    """
    print("assert_worktree_attached: bắt worktree mồ côi, tha worktree thật (#2177)")

    with tempfile.TemporaryDirectory() as td:
        root = Path(td) / "repo"
        root.mkdir()
        import subprocess
        subprocess.run(["git", "init", "-q", str(root)], check=True)

        # mồ côi: thư mục nằm TRONG repo nhưng không phải worktree đã đăng ký —
        # rev-parse từ đây im lặng trả về gốc repo cha, đúng cái bẫy của #2177
        orphan = root / ".claude" / "worktrees" / "issue-9"
        orphan.mkdir(parents=True)
        check(tal.worktree_attached(orphan) is False, "thư mục mồ côi → False")
        try:
            tal.assert_worktree_attached(orphan)
        except tal.Fail as e:
            check("MỒ CÔI" in str(e) and "repo cha" in str(e),
                  "lỗi nói rõ: worktree mồ côi — git đang trỏ về repo cha", str(e))
        else:
            check(False, "phải RAISE cho thư mục mồ côi")

        # thật: gốc repo là toplevel của chính nó → guard cho qua
        check(tal.worktree_attached(root) is True, "toplevel thật → True")
        try:
            tal.assert_worktree_attached(root)
            check(True, "worktree thật không bị chặn oan")
        except tal.Fail as e:
            check(False, "worktree thật không bị chặn oan", str(e))

        # ngoài mọi repo: rev-parse thoát ≠ 0 → cũng là mồ côi
        stray = Path(td) / "stray"
        stray.mkdir()
        check(tal.worktree_attached(stray) is False, "ngoài repo → False")


# ─────────────────────────────────────────────────────────────────────────────
# Lỗi #1406 — cờ LỆCH chỉ đúng cho lease ISSUE, không đúng cho lease REVIEW
# ─────────────────────────────────────────────────────────────────────────────

def test_status_drift_flag_spares_review_leases():
    """#1406 — lease review KHÔNG ghi vào sổ, và đó là thiết kế.

    `cmd_claim` ghi đủ `led["lease"]`; `cmd_review_claim` thì chỉ đặt
    `state="reviewing"` + một dòng history, quyền sở hữu nằm trọn ở git ref. Cờ
    "ref còn mà sổ trống" (#1385) không phân biệt hai loại nên gắn cờ MỌI lease
    review khoẻ mạnh — và lời khuyên kèm theo là `tal unlock`, tức cướp lease của
    người đang review.
    """
    print("cmd_status: cờ LỆCH chỉ cho lease issue, không cho lease review (#1406)")

    ledgers = {
        # review đang sống: state=reviewing, lease=null — ĐÚNG THIẾT KẾ
        1392: {"issue": 1392, "state": "reviewing", "epoch": 2, "lease": None},
        # issue: sổ trống mà ref còn ⇒ LỆCH thật
        1353: {"issue": 1353, "state": "queued", "epoch": 4, "lease": None},
        # issue lành: sổ ghi đủ chủ sở hữu
        1400: {"issue": 1400, "state": "executing", "epoch": 1,
               "lease": {"session": "abcd1234", "ttl": 2700}},
    }
    tal.refs_all_full = lambda: [{"key": k, "sha": "cafe", "type": "commit"}
                                 for k in ("pr-1402", "issue-1353", "issue-1400")]
    tal.pr_issue = lambda pr: 1392
    tal.ledger_read = lambda n: (ledgers[n], 1, tal.now())
    tal.lease_expired = lambda d, upd: False
    tal.open_issues = lambda: []
    tal.gh_json = lambda args, default=None: [] if default is None else default
    tal.live_lease_issues = lambda: set()

    class A:
        json = True

    board = tal.cmd_status(A())
    by_key = {r["key"]: r for r in board["leases"]}

    check(by_key["pr-1402"]["orphan_ref"] is False,
          "lease REVIEW đang sống KHÔNG bị gắn cờ lệch",
          "gắn cờ ở đây là xui người chạy `tal unlock` lên lease của người khác")
    check(by_key["issue-1353"]["orphan_ref"] is True,
          "lease ISSUE có ref mà sổ trống VẪN bị gắn cờ (không nới rào)")
    check(by_key["issue-1400"]["orphan_ref"] is False,
          "lease ISSUE lành lặn không bị gắn cờ")


# ─────────────────────────────────────────────────────────────────────────────
# Lỗi #1400 — merge sửa index của gitlink nhưng KHÔNG move cây con
# ─────────────────────────────────────────────────────────────────────────────

def test_merge_leaves_submodule_worktree_stale():
    """#1400 — vì sao `merge-batch` phải init submodule LẦN THỨ HAI, sau khi trộn.

    Đây là test trên fixture git THẬT, không mock: điều cần chứng minh là hành vi
    của chính `git`, nên mock nó đi thì test chỉ còn kiểm lại giả định của mình.

        sub:   c1 ── c2 ── c3
        super: base@c1 ;  prA→c3 ;  prB→c2

    Trộn prA rồi prB: git tự giải gitlink ("Note: Fast-forwarding submodule"), index
    thành c3 — nhưng cây con vẫn nằm ở c2, nên file đọc ra nội dung CŨ.
    """
    print("merge để cây con lệch pointer; `submodule update` kéo về (#1400)")

    import shutil
    import subprocess

    if not shutil.which("git"):
        check(False, "cần git để chạy test này", "không tìm thấy git trong PATH")
        return

    env = {**os.environ, "GIT_AUTHOR_NAME": "t", "GIT_AUTHOR_EMAIL": "a@b.c",
           "GIT_COMMITTER_NAME": "t", "GIT_COMMITTER_EMAIL": "a@b.c",
           "GIT_CONFIG_GLOBAL": os.devnull, "GIT_CONFIG_SYSTEM": os.devnull}

    def git(*args, cwd):
        return subprocess.run(["git", "-c", "protocol.file.allow=always", *args],
                              cwd=str(cwd), env=env, capture_output=True, text=True)

    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        sub, super_ = root / "sub", root / "super"

        sub.mkdir()
        git("init", "-q", "-b", "main", ".", cwd=sub)
        shas = []
        for i in ("1", "2", "3"):
            (sub / "f").write_text(i)
            git("add", "f", cwd=sub); git("commit", "-qm", f"c{i}", cwd=sub)
            shas.append(git("rev-parse", "HEAD", cwd=sub).stdout.strip())
        c1, c2, c3 = shas

        super_.mkdir()
        git("init", "-q", "-b", "main", ".", cwd=super_)
        git("submodule", "add", "-q", str(sub), "sub", cwd=super_)
        git("-C", "sub", "checkout", "-q", c1, cwd=super_)
        git("add", ".gitmodules", "sub", cwd=super_); git("commit", "-qm", "base", cwd=super_)

        for br, sha in (("prA", c3), ("prB", c2)):
            git("checkout", "-q", "main", cwd=super_)
            git("checkout", "-q", "-b", br, cwd=super_)
            git("-C", "sub", "checkout", "-q", sha, cwd=super_)
            git("add", "sub", cwd=super_); git("commit", "-qm", br, cwd=super_)

        git("checkout", "-q", "main", cwd=super_)
        for br in ("prA", "prB"):
            m = git("merge", "--no-edit", br, cwd=super_)
            check(m.returncode == 0, f"trộn {br} không conflict",
                  (m.stdout + m.stderr).strip()[:200])

        def index_ptr():
            return git("ls-files", "-s", "sub", cwd=super_).stdout.split()[1]

        def worktree_ptr():
            return git("-C", "sub", "rev-parse", "HEAD", cwd=super_).stdout.strip()

        # Đây là lỗi. Nếu một ngày git tự đồng bộ cây con thì hai dòng này đỏ — và
        # đó là tin tốt: lúc ấy bước init thứ hai trong merge-batch mới là thừa.
        check(index_ptr() == c3, "index mang pointer của lô (c3)", index_ptr()[:12])
        check(worktree_ptr() == c2,
              "cây con VẪN ở pointer cũ (c2) — merge không move nó",
              f"worktree={worktree_ptr()[:12]}")
        check((super_ / "sub" / "f").read_text() == "2",
              "file trong submodule đọc ra nội dung CŨ ⇒ full suite sẽ test nhầm code")

        # Và đây là bản sửa: đúng một lệnh, chính là lệnh merge-batch chạy lần hai.
        u = git("submodule", "update", "--init", cwd=super_)
        check(u.returncode == 0, "`git submodule update --init` chạy được",
              (u.stdout + u.stderr).strip()[:200])
        check(worktree_ptr() == c3, "sau khi đồng bộ: cây con khớp index",
              f"worktree={worktree_ptr()[:12]} index={index_ptr()[:12]}")
        check((super_ / "sub" / "f").read_text() == "3",
              "file đọc ra ĐÚNG nội dung của lô sắp merge")
        check(git("status", "--porcelain", cwd=super_).stdout.strip() == "",
              "cây sạch — không để lại ' M sub' cho bước sau hiểu nhầm")


# ─────────────────────────────────────────────────────────────────────────────
# Lỗi #1413 — branch có PR ĐANG MỞ không được xoá, dù PR cũ cùng branch đã merge
# ─────────────────────────────────────────────────────────────────────────────

def test_gc_spares_branch_with_open_pr():
    """#1413 — một branch được DÙNG LẠI qua nhiều vòng PR.

    Vòng 1 merge → vòng sửa mở PR mới từ cùng `issue-<N>` → `gc` thấy PR-đã-merge
    mang head đó và xoá branch, GitHub đóng luôn PR mới. Đã mất trắng hai PR đã
    test xong theo đúng đường này (closed 01:58:50, head_ref_deleted 01:58:51).

    Rào của #1385 không với tới: nó chừa issue có LEASE SỐNG, mà `tal pr` nhả
    lease ngay sau khi mở PR — đúng thiết kế. Khoảng mở-PR→merge là lúc branch
    cần được bảo vệ nhất và lại đang trần.
    """
    print("gc: chừa branch đang có PR MỞ (#1413)")

    deleted: list[str] = []

    def fake_gh_json(args, default=None):
        if "pr" in args and "--state" in args and "merged" in args:
            return [
                {"number": 1402, "headRefName": "issue-1392",
                 "headRepositoryOwner": {"login": "o"}},
                {"number": 1300, "headRefName": "issue-1299",
                 "headRepositoryOwner": {"login": "o"}},
            ]
        if "pr" in args and "--state" in args and "open" in args:
            # Vòng sửa: PR MỚI mở từ chính branch mà PR 1402 đã merge.
            return [{"headRefName": "issue-1392"}]
        return [{"ref": "refs/heads/issue-1392"}, {"ref": "refs/heads/issue-1299"}]

    tal.gh_json = fake_gh_json
    tal.gh = lambda args, check=True: deleted.append(args[-1]) or type("P", (), {})()

    acts = tal.delete_merged_branches("o/r", dry=False, protect={"main", "dev", "master"})
    killed = [a["branch"] for a in acts if "xoá" in a["action"]]
    spared = [a["branch"] for a in acts if "BỎ QUA" in a["action"]]

    check("issue-1392" not in killed,
          "KHÔNG xoá branch đang có PR mở, dù PR cũ cùng branch đã merge",
          "đây là đường đã mất trắng hai PR")
    check("issue-1392" in spared, "và nói rõ vì sao bỏ qua", str(acts))
    check("issue-1299" in killed,
          "vẫn dọn branch của PR đã merge mà KHÔNG còn PR mở — rào không được nới",
          str(killed))
    check(not any("issue-1392" in d for d in deleted),
          "không có lời gọi DELETE nào chạm branch được chừa", str(deleted))



# ─────────────────────────────────────────────────────────────────────────────
# #1397 — tách vai cưỡng chế ở CẢ BA cửa, không chỉ review-claim
# ─────────────────────────────────────────────────────────────────────────────

def test_separation_of_duty():
    print("tách vai: chặn ở claim + verdict + merge (#1397)")

    tal.session_id = lambda: "aaaa1111-coder"
    # #2300 D5 — "đã CODE" = đã BÀN GIAO PR, không phải đã từng claim: nguồn chính
    # là trường `coders`, fallback dòng "worker session …" cho sổ cũ.
    led_mine = {"issue": 9, "coders": ["aaaa1111"],
                "history": ["2026-01-01 · PR #12 mở, bàn giao review (worker session aaaa1111…)"]}
    led_other = {"issue": 9,
                 "history": ["2026-01-01 · claim epoch=1 session=aaaa1111 wt=issue-9",
                             "2026-01-01 · PR #12 mở, bàn giao review (worker session bbbb2222…)"]}

    check(tal.coder_sessions(led_mine) == {"aaaa1111"}, "đọc đúng session đã code từ ledger")
    check(tal.coder_sessions(led_other) == {"bbbb2222"},
          "#2091: claim-để-đọc KHÔNG biến session thành tác giả — chỉ bàn giao PR mới tính",
          str(tal.coder_sessions(led_other)))

    def blocked(led, allow=False):
        try:
            tal.assert_not_own_work(led, 9, "merge", allow, "--self")
            return False
        except tal.Fail:
            return True

    check(blocked(led_mine), "PR của chính mình → CHẶN")
    check(not blocked(led_other), "PR của session khác → cho qua")
    check(not blocked(led_mine, allow=True), "có cờ tường minh → cho qua")

    # Ba cửa phải dùng CHUNG một hàm — chặn một cửa mà quên hai cửa kia là lỗ hổng
    # nguyên bản (#1397): bị từ chối ở `review-claim` rồi đi thẳng qua `review-verdict`.
    src = (HERE / "tal").read_text()
    for fn in ("cmd_review_claim", "cmd_review_verdict", "cmd_merge"):
        blk = src[src.index(f"def {fn}("):]
        blk = blk[:blk.index("\ndef ", 10)]
        check("assert_not_own_work" in blk, f"{fn} có gọi rào tách vai")

    # `--self` không kèm `--note` phải bị từ chối: tự merge im lặng là thứ cần chặn.
    blk = src[src.index("def cmd_merge("):]
    blk = blk[:blk.index("\ndef ", 10)]
    check("--note" in blk and "bắt buộc kèm" in blk, "`--self` đòi `--note`, không cho im lặng")
    check("batch_verified" in blk.split("assert_not_own_work")[0].splitlines()[-3][:200] or
          "not a.batch_verified" in blk, "merge-batch (đã qua cổng) được miễn")


# ─────────────────────────────────────────────────────────────────────────────
# #1462 — đóng issue khi merge vào `dev`; pointer phải so trong ĐÚNG repo con
# ─────────────────────────────────────────────────────────────────────────────

def test_closable_spares_unfinished_work():
    """Merge vào `dev` là đóng — TRỪ issue còn mang một `status:*` khác.

    `tal merge` gắn `status:shipped` cho MỌI PR merge, kể cả PR mới xong một pha
    của một epic. #962 (Modular Monolith) vì thế mang cả `status:planning` lẫn
    `status:shipped`; #1392 mang cả `status:blocked`. Đóng chúng là xoá việc còn
    dang dở khỏi tầm mắt — cả hai đã phải giữ lại bằng tay ngày 2026-08-01.
    """
    print("closable: đóng cái đã xong, chừa cái còn nhãn trạng thái khác (#1462)")

    def data(state, labels):
        return lambda n: {"number": n, "state": state, "labels": labels}

    tal.issue_data = data("open", ["bug", "status:shipped"])
    ok, why = tal.closable(1)
    check(ok and why == "", "issue chỉ có status:shipped → ĐÓNG")

    tal.issue_data = data("open", ["enhancement", "status:shipped", "status:planning"])
    ok, why = tal.closable(962)
    check(not ok and "status:planning" in why,
          "epic mang thêm status:planning → KHÔNG đóng, và nói rõ vì sao",
          "đóng epic mới xong một pha là xoá việc dang dở khỏi tầm mắt")

    tal.issue_data = data("open", ["status:shipped", "status:blocked"])
    check(tal.closable(1392)[0] is False, "còn status:blocked → KHÔNG đóng")

    tal.issue_data = data("open", ["status:shipped", "agent:ready"])
    check(tal.closable(2)[0] is True, "nhãn agent:* không chặn — chỉ status:* mới chặn")

    tal.issue_data = data("closed", ["status:shipped"])
    ok, why = tal.closable(3)
    check(ok is False and why == "", "issue đã đóng thì im lặng bỏ qua, không báo gì")


def test_merge_and_gc_close_through_the_same_gate():
    """`Closes #N` không bao giờ chạy ở quy trình này.

    GitHub chỉ tự đóng khi PR merge vào DEFAULT BRANCH, mà default của repo là
    `main` còn mọi PR ở đây nhắm `dev`. Trước #1462 cũng không có `gh issue
    close` ở đâu — issue nằm OPEN + status:shipped vô thời hạn (22 cái, dọn tay
    ngày 2026-08-01).
    """
    print("cmd_merge + gc: cùng đi qua closable(), gc đóng theo mặc định (#1462)")
    src = (HERE / "tal").read_text()

    blk = src[src.index("def cmd_merge("):]
    blk = blk[:blk.index("\ndef ", 10)]
    check('"issue", "close"' in blk, "cmd_merge gọi `gh issue close`")
    check("closable(" in blk, "cmd_merge đi qua rào closable, không đóng thẳng tay")
    check(blk.index("set_state_labels") < blk.index('"issue", "close"'),
          "đóng SAU khi gắn nhãn — nhãn phải còn trên issue đã đóng để tra cứu")

    gc = src[src.index("def cmd_gc("):]
    gc = gc[:gc.index("\ndef ", 10)]
    check("no_close" in gc and "closable(" in gc,
          "gc đóng theo mặc định và dùng CHUNG rào với cmd_merge",
          "hai đường đóng khác luật là hai lần cơ hội lệch nhau")


def test_realign_checks_submodule_is_checked_out():
    """#1462 — git trong thư mục submodule RỖNG leo lên repo cha.

    Khi đó `origin/dev` là tip của UMBRELLA, nên phép so tổ tiên đem pointer của
    submodule so với SHA của repo khác: luôn "phân kỳ", luôn chặn merge. Đã chặn
    thật PR #1415 với `pos-web (061da1cbf) KHÔNG phải tổ tiên của tip 42aa84a04`
    — trong đó 42aa84a04 là commit UMBRELLA, không tồn tại trong pos-web.
    """
    print("realign_pointers: bắt buộc submodule đã checkout trước khi so (#1462)")
    src = (HERE / "tal").read_text()
    blk = src[src.index("def realign_pointers("):]
    blk = blk[:blk.index("\ndef ", 10)]
    check("--show-toplevel" in blk,
          "kiểm toplevel của repo con khớp đúng thư mục đó",
          "không kiểm thì git lặng lẽ trả lời bằng repo cha")
    check(blk.index("--show-toplevel") < blk.index("merge-base"),
          "kiểm TRƯỚC khi so tổ tiên, không phải sau")
    check('"submodule", "update", "--init"' in blk,
          "tự init submodule chưa checkout thay vì chỉ báo lỗi")


# ─────────────────────────────────────────────────────────────────────────────
# #1465 — con trỏ chỉ sống nhờ nhánh feature; PR con nhắm sai base
# ─────────────────────────────────────────────────────────────────────────────

def test_audit_blocks_child_pr_on_wrong_base():
    """PR con nhắm base ≠ `dev` phải CHẶN.

    `customer-web#96` nhắm `info-customer`: PR merge, `tal gc` xoá nhánh
    `issue-N`, và commit mà umbrella đang trỏ tới chỉ còn sống nhờ một nhánh
    feature. Nhánh đó biến mất là mọi clone mới chết ở `submodule update`.
    """
    print("submodule_audit: PR con nhắm sai base thì CHẶN (#1465)")
    src = (HERE / "tal").read_text()
    blk = src[src.index("def submodule_audit("):]
    blk = blk[:blk.index("\ndef ", 10)]
    check("baseRefName" in blk, "audit đọc base của PR con")
    check("wrong-base" in blk, "có mã lỗi riêng cho base sai, không lẫn vào no-pr")
    # #2300 F6 — chuẩn so là branch .gitmodules khai (chính là base submodule-pr
    # DÙNG để tạo PR); so cứng BASE_BRANCH của umbrella là tal tự tạo PR ở base X
    # rồi tự chặn chính PR đó khi submodule track branch khác dev.
    check("b != want_base" in blk and 's.get("branch")' in blk,
          "so với branch của .gitmodules (want_base), không hard-code tên nhánh",
          "so cứng BASE_BRANCH umbrella thì submodule track nhánh khác là tự chặn PR mình tạo")


def test_doctor_scans_for_dangling_pointers():
    """`tal doctor` phải quét được đúng lỗi #1465 bằng MỘT lệnh.

    Phép kiểm đúng KHÔNG phải "commit có trên origin/issue-N" — nhánh đó rồi sẽ
    bị xoá — mà là "sau khi mọi nhánh tạm biến mất, commit còn với tới được từ
    `dev` của repo con không".
    """
    print("doctor: quét con trỏ chỉ sống nhờ nhánh feature (#1465)")
    src = (HERE / "tal").read_text()
    check("def dangling_pointers(" in src, "có hàm quét riêng, gọi lại được")
    blk = src[src.index("def dangling_pointers("):]
    blk = blk[:blk.index("\ndef ", 10)]
    check('f"origin/{BASE_BRANCH}"' in blk,
          "đối chiếu với origin/dev của REPO CON",
          "đối chiếu với issue-N là kiểm nhầm thứ sắp bị xoá")
    check("cat-file" in blk and "merge-base" in blk,
          "kiểm cả hai ca: commit không tồn tại, và tồn tại nhưng ngoài dev")
    check("--contains" in blk, "nói rõ nó đang sống nhờ nhánh nào")

    doc = src[src.index("def cmd_doctor("):]
    doc = doc[:doc.index("\ndef ", 10)]
    check("dangling_pointers()" in doc, "doctor thật sự gọi nó")


# ─────────────────────────────────────────────────────────────────────────────
# #1468/B — CỔNG HỎNG phân biệt theo TRIỆU CHỨNG, không theo vị trí gọi
# ─────────────────────────────────────────────────────────────────────────────

def test_broken_gate_beats_suite_red():
    """Một lệnh suite chết vì môi trường KHÔNG được báo là "full suite ĐỎ".

    Trước #1468 hai loại được phân biệt bằng `fail_msg` truyền vào — tức bằng VỊ
    TRÍ gọi. Nên `vendor/` biến mất giữa chừng vẫn ra nhãn test đỏ, và người đọc
    đi tìm một test hỏng không tồn tại (#1409). Không một assertion nào chạy.
    """
    print("run_stage: triệu chứng thắng vị trí gọi (#1468/B)")
    import tempfile as _tf
    from pathlib import Path as _P

    with _tf.TemporaryDirectory() as td:
        tmp = _P(td)

        pest_panic = 'PHP Fatal error: Uncaught Error: Class "Pest\\Panic" not found in ' \
                     '/x/_batch-102e6330/backend/vendor/pestphp/pest/bin/pest:211'
        check(tal.looks_like_broken_gate(tmp, pest_panic) is not None,
              "`Class \"Pest\\Panic\" not found` → CỔNG HỎNG",
              "đây là dấu vết y hệt #1436/#1409, hai lần liên tiếp")

        check(tal.looks_like_broken_gate(tmp, "Could not open input file: vendor/bin/pest") is not None,
              "`Could not open input file` → CỔNG HỎNG")
        check(tal.looks_like_broken_gate(tmp, "sh: tsc: command not found") is not None,
              "`command not found` → CỔNG HỎNG")

        real_red = "Tests:  3 failed, 120 passed (450 assertions)\n  FAILED  OrderTest > it voids"
        check(tal.looks_like_broken_gate(tmp, real_red) is None,
              "suite đỏ THẬT vẫn là suite đỏ",
              "nếu cái này cũng thành CỔNG HỎNG thì rào nuốt mất lỗi code")

    # Cây tạm biến mất giữa chừng — dấu hiệu mạnh nhất, không cần đọc output.
    gone = _P(td)
    check(tal.looks_like_broken_gate(gone, "bất kỳ output nào") is not None,
          "cây tạm biến mất → CỔNG HỎNG, không cần đoán qua output")


# ─────────────────────────────────────────────────────────────────────────────
# #1468/C — gc không được cướp lease vừa claim
# ─────────────────────────────────────────────────────────────────────────────

def test_gc_spares_lease_being_claimed():
    """`cmd_claim` tạo ref TRƯỚC, ghi sổ SAU. Cửa sổ đó không phải rác.

    Rơi vào đúng cửa sổ ấy, `gc` xoá ref của người đang làm: `tal queue` mời
    session khác vào nhận, và `tal assert` của chính chủ fail giữa lúc họ đang
    sửa — commit của họ thành rác. Bắt được tại trận với mốc giờ trong #1404.
    """
    print("reap_leases: chừa lease đang trong cửa sổ claim (#1468/C)")
    import os as _os
    import tempfile as _tf
    from pathlib import Path as _P

    td = _tf.mkdtemp()

    class Ctx:
        state_dir = _P(td) / "state"

    Ctx.state_dir.mkdir(parents=True, exist_ok=True)
    tal.C = Ctx()

    key = "issue-4242"
    lock = Ctx.state_dir / f"{key}.lock"
    lock.mkdir(parents=True, exist_ok=True)

    # (1) khoá cục bộ còn pid SỐNG ⇒ có người đang claim ngay lúc này
    (lock / "meta.json").write_text(json.dumps({"pid": _os.getpid(), "session": "X"}))
    stale, why = tal.orphan_ref_is_stale(key)
    check(stale is False and "đang claim" in why,
          "khoá cục bộ còn sống → KHÔNG xoá ref",
          "`cmd_claim` gọi local_lock TRƯỚC ref_create, nên bằng chứng luôn có sẵn")

    # (2) pid chết, lần ĐẦU thấy mồ côi ⇒ vẫn chưa xoá, chỉ ghi mốc
    (lock / "meta.json").write_text(json.dumps({"pid": 999_999_999, "session": "X"}))
    tal.orphan_seen_clear(key)
    stale, why = tal.orphan_ref_is_stale(key)
    check(stale is False and "lần đầu" in why,
          "lần đầu thấy mồ côi → ghi mốc, chưa xoá",
          "xoá ngay lần đầu là quay lại đúng lỗi #1404 với máy không có khoá cục bộ")

    # (3) đã quá ân hạn ⇒ mới được xoá
    tal._orphan_seen_file(key).write_text(json.dumps({"at": 0}))
    stale, why = tal.orphan_ref_is_stale(key)
    check(stale is True, f"quá {tal.ORPHAN_GRACE}s mà vẫn thiếu sổ → mới xoá")

    # (4) có sổ trở lại thì quên mốc, không cộng dồn sang lần mồ côi sau
    tal.orphan_seen_clear(key)
    check(not tal._orphan_seen_file(key).exists(), "có sổ trở lại thì xoá mốc mồ côi")

    check(tal.ORPHAN_GRACE >= 60,
          "ân hạn đủ rộng so với vài giây của claim",
          "ân hạn quá ngắn thì rào chỉ là trang trí")


# ─────────────────────────────────────────────────────────────────────────────
# #1472 — rào lease xét CWD, nên chỉ được áp cho lệnh thật sự GHI VÀO ĐĨA
# ─────────────────────────────────────────────────────────────────────────────

def test_hook_guard_lets_github_through():
    """`gh` ghi lên GitHub, không ghi vào cây làm việc.

    Đứng trong worktree đã nhả lease (trạng thái BÌNH THƯỜNG sau `tal pr`) mà
    chạy `gh issue edit` thì bị chặn — rào lấy vị trí thư mục ra phán về một
    lệnh không đụng thư mục nào. Chặn 5 lần trong một phiên, không lần nào là
    thao tác nguy hiểm. Cùng lớp lỗi #1382, còn sót lại.
    """
    print("hook guard: `gh` và `tal merge` đi qua; `gh pr checkout` vẫn chặn (#1472)")

    def gated(cmd: str) -> bool:
        return bool(tal.bash_write_targets(cmd, "/locked"))

    for cmd in ("gh issue edit 1449 --add-label agent:review-passed",
                "gh pr comment 161 --body ok",
                "gh pr merge 1471 --merge",
                "gh issue close 1424"):
        check(not gated(cmd), f"KHÔNG chặn: {cmd[:40]}")

    check(not gated(".claude/tools/agent-loop/tal merge 1471 --self --note x"),
          "KHÔNG chặn `tal merge` — nó không ghi vào worktree của issue")

    # Ngoại lệ phải giữ: đây mới là những `gh` thật sự ghi vào cây làm việc.
    for cmd in ("gh pr checkout 161", "gh repo clone godx-jp/x", "gh repo sync"):
        check(gated(cmd), f"VẪN chặn: {cmd}", "ba lệnh này thật sự ghi vào cây làm việc")

    # Và phần còn lại của rào không được nới.
    for cmd in ("git commit -m x", "rm -rf build", "sed -i '' s/a/b/ f.php",
                "composer install", "echo x > f.txt"):
        check(gated(cmd), f"VẪN chặn: {cmd[:30]}")


# ─────────────────────────────────────────────────────────────────────────────
# #1476 — claim phải re-entrant với lease của CHÍNH MÌNH
# ─────────────────────────────────────────────────────────────────────────────

def test_claim_reenters_own_lease():
    """`ref_create` chỉ biết ref CÓ tồn tại, không biết AI giữ.

    `local_lock` đã re-entrant cho cùng session, nên hai tầng bất đối xứng: một
    session quay lại issue của chính nó bị báo "session khác đang giữ". Thông
    điệp sai đó đẩy người vận hành về phía nguy hiểm — phản ứng đúng (làm tiếp)
    trông như phạm luật, còn `tal unlock` để cướp lease thì lại được chính thông
    điệp gợi ý.
    """
    print("claim: nhận lại lease của chính mình, không báo 'session khác' (#1476)")

    tal.session_id = lambda: "MINE1234-aaaa"

    # lease của chính mình, còn hạn
    tal.ledger_read = lambda n: ({"issue": n, "lease": {"session": "MINE1234-aaaa"}}, 1, tal.now())
    tal.lease_expired = lambda d, upd: False
    check(tal.lease_is_mine(1180) is True, "lease của mình + còn hạn → nhận lại")

    # lease của mình nhưng ĐÃ HẾT HẠN → không tính là của mình
    tal.lease_expired = lambda d, upd: True
    check(tal.lease_is_mine(1180) is False,
          "lease của mình nhưng hết hạn → KHÔNG nhận lại",
          "quá TTL là đã mất, phải đi qua đường thu hồi bình thường")

    # lease của session khác
    tal.lease_expired = lambda d, upd: False
    tal.ledger_read = lambda n: ({"issue": n, "lease": {"session": "OTHER999-bbbb"}}, 1, tal.now())
    check(tal.lease_is_mine(1180) is False, "lease của session khác → vẫn CHẶN")

    # không có lease trong sổ
    tal.ledger_read = lambda n: ({"issue": n}, 1, tal.now())
    check(tal.lease_is_mine(1180) is False, "sổ không có lease → không tự nhận bừa")


def test_claim_rollback_spares_adopted_refs():
    """Rollback chỉ được trả lại ref MÌNH VỪA TẠO.

    Xoá cả ref chỉ nhận lại là tự tay vứt một lease đang giữ — biến một lần
    claim hỏng thành mất quyền ghi trên việc đang làm dở.
    """
    print("claim: rollback chừa ref chỉ nhận lại, không xoá bừa (#1476)")
    src = (HERE / "tal").read_text()
    blk = src[src.index("def cmd_claim("):]
    blk = blk[:blk.index("\ndef ", 10)]

    check("adopted" in blk and "created" in blk,
          "tách hai danh sách: ref vừa tạo vs ref nhận lại")
    roll = blk[blk.index("except Fail:"):]
    check("for k in created:" in roll, "rollback chỉ duyệt `created`")
    check("for k in adopted:" not in roll, "rollback KHÔNG đụng `adopted`")
    check("lease_is_mine(" in blk, "claim hỏi sổ khi ref đã tồn tại")


# ─────────────────────────────────────────────────────────────────────────────
# #1487 — cổng con trỏ ở LÚC MERGE, lớp cuối tal cưỡng chế được
# ─────────────────────────────────────────────────────────────────────────────

def test_merge_gates_on_dangling_pointer():
    """Umbrella không được merge khi con trỏ chưa nằm trên `dev` repo con.

    Ba lớp trước đều hụt: `submodule_audit` chỉ chạy lúc `tal pr`;
    `dangling_pointers()` chỉ nằm trong `doctor` (phải tự nhớ chạy);
    `realign_pointers()` chỉ căn khi con trỏ là TỔ TIÊN của tip — con trỏ chết
    thì không phải tổ tiên của gì cả nên nó im lặng bỏ qua.

    Lọt BA LẦN trong ngày 2026-08-01, cả ba là `customer-web` trỏ vào commit chỉ
    sống trên nhánh feature ⇒ clone mới chết ở `submodule update`.
    """
    print("cmd_merge: chặn con trỏ chưa vào dev repo con (#1487)")
    src = (HERE / "tal").read_text()

    check("def pr_dangling_pointers(" in src, "có hàm kiểm riêng, gọi lại được")

    fn = src[src.index("def pr_dangling_pointers("):]
    fn = fn[:fn.index("\ndef ", 10)]
    check("headRefName" in fn, "đọc con trỏ từ HEAD của PR, không phải từ dev")
    check('f"origin/{BASE_BRANCH}"' in fn, "đối chiếu với origin/dev của REPO CON")
    check("cat-file" in fn and "merge-base" in fn,
          "bắt cả hai ca: commit không tồn tại, và tồn tại nhưng ngoài dev")
    check("--contains" in fn, "nói rõ con trỏ đang sống nhờ nhánh nào")

    blk = src[src.index("def cmd_merge("):]
    blk = blk[:blk.index("\ndef ", 10)]
    check("pr_dangling_pointers(pr)" in blk, "cmd_merge thật sự gọi cổng")
    # Thứ tự quan trọng: phải SAU merge_sub_prs/realign (con trỏ còn có thể được
    # sửa), và TRƯỚC `gh pr merge` (sau đó thì đã muộn).
    check(blk.index("merge_sub_prs(") < blk.index("pr_dangling_pointers(pr)"),
          "chạy SAU khi cụm repo con đã merge + căn lại",
          "chạy trước thì chặn oan đúng những PR mà realign sắp sửa xong")
    check(blk.index("pr_dangling_pointers(pr)") < blk.index('"pr", "merge"'),
          "chạy TRƯỚC khi merge thật")
    check("a.force" in blk.split("pr_dangling_pointers(pr)")[1][:400],
          "`--force` mở được, nhưng phải tường minh")


# ─────────────────────────────────────────────────────────────────────────────
# #1524 — `tal pr-merge`: cả chuỗi merge trong một lệnh
# ─────────────────────────────────────────────────────────────────────────────

def test_wait_checks_classifies_states():
    """SKIPPED/NEUTRAL không phải đỏ, cũng không phải "chưa xong".

    Sau #1516 job `pest` có `if:` không thoả khi PR nhắm `dev`, nên nó nằm mãi ở
    SKIPPED. Coi trạng thái đó là pending thì lệnh chờ vô hạn; coi là đỏ thì
    không PR nào vào `dev` được nữa.
    """
    print("wait_checks: phân loại đúng SUCCESS / SKIPPED / FAILURE / pending (#1524)")

    def rows(*st):
        return [{"name": f"c{i}", "state": x} for i, x in enumerate(st)]

    tal.gh_json = lambda *a, **k: rows("SUCCESS", "SKIPPED")
    ok, msg = tal.wait_checks("r", 1, timeout=0)
    check(ok is True, "SUCCESS + SKIPPED → xanh", msg)

    tal.gh_json = lambda *a, **k: rows("SUCCESS", "FAILURE")
    ok, msg = tal.wait_checks("r", 1, timeout=0)
    check(ok is False and "FAILURE" in msg, "có FAILURE → đỏ, và nói rõ check nào")

    tal.gh_json = lambda *a, **k: rows("IN_PROGRESS")
    ok, msg = tal.wait_checks("r", 1, timeout=0)
    check(ok is False and "chưa xong" in msg,
          "quá timeout mà còn pending → dừng, không chờ vô hạn")

    tal.gh_json = lambda *a, **k: []
    ok, msg = tal.wait_checks("r", 1, timeout=0)
    check(ok is True, "repo không có CI → coi là xanh",
          "bắt mọi repo con phải có CI mới merge được là dựng rào không ai xin")


def test_pr_merge_never_sets_review_label():
    """`pr-merge` KHÔNG được tự gắn `agent:review-passed`.

    Nhãn đó là bằng chứng đã có người đọc lại. Lệnh tự gắn thì nó chỉ còn là một
    bước thủ tục, và cổng tách vai — lý do tồn tại của cả vòng lặp — chết theo.
    """
    print("pr-merge: dừng khi thiếu review, KHÔNG tự gắn nhãn (#1524)")
    src = (HERE / "tal").read_text()
    blk = src[src.index("def cmd_pr_merge("):]
    blk = blk[:blk.index("\ndef ", 10)]

    check("set_state_labels" not in blk and "add-label" not in blk,
          "không có chỗ nào gắn nhãn",
          "tự gắn là vô hiệu hoá cổng tách vai")
    check("merge_blockers(" in blk, "dùng chung cổng với `tal merge`")
    check("L_PASSED" in blk, "thông điệp nêu đúng tên nhãn còn thiếu")
    check("cmd_merge(a)" in blk,
          "kết thúc bằng cmd_merge — không chép lại logic merge",
          "chép lại là hai đường merge khác luật, đúng thứ #1462 đã phải sửa")

    # Thứ tự chặng: chờ CI của CON phải đứng trước chờ CI của UMBRELLA. So bằng
    # đúng hai lời gọi, không so bằng chữ trong docstring.
    check(blk.index('wait_checks(sp[') < blk.index("wait_checks(C.repo"),
          "chờ CI PR con trước, umbrella sau",
          "đảo lại là chờ umbrella xanh rồi mới biết con đỏ — mất cả lượt chờ")


# ─────────────────────────────────────────────────────────────────────────────
# #1528 — rào lease: nhận `tal` là họ, và xét TỪNG ĐOẠN lệnh
# ─────────────────────────────────────────────────────────────────────────────

def test_hook_guard_exempts_tal_family_per_segment():
    """Hai lỗi cùng lúc trong bản rào cũ.

    (1) DANH SÁCH BỎ SÓT: TAL_RE liệt kê từng tên lệnh, nên `tal pr-merge`
        (#1524) bị chặn ngay hôm nó ra đời — cùng `merge-batch`, `merge-queue`,
        `review-claim`, `review-verdict`, `submodule-pr`, `submodule-check`.
        Bảy lệnh, không lệnh nào ghi vào worktree người khác.

    (2) ĐI KÉ: rào xét CẢ DÒNG, nên `tal claim 1 && git commit -m x` được miễn
        trọn vẹn — một lệnh ghi thật núp sau một lệnh được miễn.
    """
    print("hook guard: `tal` là họ, và xét từng đoạn lệnh (#1528)")
    def gated(cl: str) -> bool:
        return bool(tal.bash_write_targets(cl, "/locked"))

    for cmd in ("tal pr-merge 1527", "tal merge-batch", "tal review-claim 5",
                "tal submodule-pr pos-web", ".claude/tools/agent-loop/tal pr-merge 1",
                "tal merge 1 && tal gc"):
        check(not gated(cmd), f"MIỄN: {cmd[:38]}")

    check(gated("tal claim 1 && git commit -m y"),
          "CHẶN: lệnh ghi đi ké sau `tal`",
          "miễn cả dòng là mở cửa cho mọi thứ núp sau một `tal` vô hại")
    check(gated("git commit -m x && tal claim 1"), "CHẶN: đi ké đằng trước cũng vậy")
    check(gated("rm -rf b; tal status"), "CHẶN: `;` cũng là ranh giới đoạn")

    for cmd in ("git commit -m x", "rm -rf build", "gh pr checkout 3", "composer install"):
        check(gated(cmd), f"CHẶN: {cmd[:30]}")

    check(not gated("gh issue edit 5 --add-label x"),
          "MIỄN: `gh` thường vẫn qua (#1472)")

    # Và rào thật trong tal phải dùng ĐÚNG cách tách đoạn này.
    src = (HERE / "tal").read_text()
    blk = src[src.index("def bash_write_targets("):]
    blk = blk[:blk.index("\ndef ", 10)]
    check("re.split" in blk, "bộ giải đích thật xét từng đoạn, không xét cả dòng")


# ─────────────────────────────────────────────────────────────────────────────
# #1515 — `agent:changes-requested` là cổng đã mở, không phải cổng chưa mở
# ─────────────────────────────────────────────────────────────────────────────

def test_changes_requested_counts_as_open_gate():
    """`tal` xếp changes-requested là ƯU TIÊN CAO NHẤT rồi lại từ chối claim nó.

    Đo được trên #1504: `tal status` liệt nó ở "cần sửa theo review (ưu tiên)",
    `tal queue` KHÔNG liệt kê, `tal claim` báo "chưa có nhãn agent:ready". Không
    session nào nhặt được mà không --force — mà skill `issue-work` cấm --force để
    vượt cổng. Đúng thứ vòng lặp sinh ra để làm lại là thứ duy nhất nó không làm
    được.

    Nhãn đó CHỈ do `review-verdict` gắn, tức issue đã đi qua cổng `ready` một lần
    rồi: không claim được thì không có PR, không có PR thì không có review.
    """
    print("gate_open: changes-requested tính là cổng đã mở (#1515)")

    check(tal.gate_open({tal.L_READY}) is True, "agent:ready → mở")
    check(tal.gate_open({tal.L_CHANGES}) is True,
          "agent:changes-requested → mở",
          "đòi người mở cổng LẦN NỮA cho một vòng sửa là bắt phê duyệt lại thứ đã duyệt")
    check(tal.gate_open({tal.L_READY, tal.L_CHANGES}) is True, "cả hai → mở")
    check(tal.gate_open({"enhancement"}) is False, "không nhãn cổng nào → ĐÓNG")
    check(tal.gate_open(set()) is False, "rỗng → ĐÓNG")

    # Ba chỗ cổng phải dùng CHUNG một hàm, không mỗi chỗ một luật.
    src = (HERE / "tal").read_text()
    for fn in ("def parent_is_workable(", "def queue_skip_reason(", "def cmd_claim("):
        if fn not in src:
            continue
        blk = src[src.index(fn):]
        blk = blk[:blk.index("\ndef ", 10)]
        check("gate_open(" in blk, f"{fn.split('(')[0][4:]} đi qua gate_open()",
              "ba luật cổng khác nhau là ba lần cơ hội lệch nhau — chính là lỗi này")

    # Và blocked/dead vẫn phải chặn được, dù cổng đã mở.
    names = {tal.L_CHANGES, tal.L_BLOCKED}
    check(bool({tal.L_BLOCKED, tal.L_DEAD} & names),
          "blocked vẫn chặn dù cổng mở — gate_open không thay thế các rào khác")


def test_gc_spares_reopened_issue():
    """`gc` đóng lại một issue vừa được MỞ LẠI có chủ ý (#1571).

    Đo được thật, bốn lần trong một phiên: một PR làm xong MỘT PHẦN issue,
    `Closes #N` đóng nó, tôi mở lại kèm comment liệt kê phần còn lại — rồi `gc`
    ĐÓNG LẠI trong vòng 5 phút. #962 · #1564 · #1568 · #1574 đều dính.

    Hai lỗi chồng nhau, và bài test này ghim cả hai:

      1. `gc` gắn `status:shipped` VÔ ĐIỀU KIỆN rồi mới hỏi `closable()` để quyết
         định có đóng không — nên nó in "KHÔNG đóng #962: còn mang status:planning"
         NGAY SAU KHI vừa gắn `status:shipped` cho chính #962. Issue mang cả hai
         nhãn, `tal queue` lọc theo nhãn ⇒ rơi khỏi hàng đợi.

      2. `closable()` không phân biệt "chưa từng đóng" với "đã đóng rồi mở lại".
         Nhãn `status:*` không cứu được: issue mở lại thường mang `agent:ready`.
    """
    print("gc: issue MỞ LẠI sau khi PR merge thì không được đụng vào (#1571)")

    src = (HERE / "tal").read_text()

    check("def reopened_after(" in src,
          "có hàm reopened_after()",
          "không có tín hiệu nào khác phân biệt được reopen cố ý với chưa-từng-đóng")

    blk = src[src.index("def closable("):]
    blk = blk[:blk.index("\ndef ", 10)]
    check("reopened_after(" in blk, "closable() hỏi reopened_after()")
    check("merged_at" in blk, "closable() nhận mốc thời gian merge")

    # Fail-safe: đọc timeline hỏng thì phải nghiêng về KHÔNG đụng vào issue.
    rblk = src[src.index("def reopened_after("):]
    rblk = rblk[:rblk.index("\ndef ", 10)]
    check("return True" in rblk.split("is None")[1][:120] if "is None" in rblk else False,
          "timeline không đọc được ⇒ coi như đã mở lại (fail-safe)",
          "đóng nhầm là mất việc; bỏ qua nhầm chỉ là một dòng nhãn cũ")

    # Lỗi gốc: gắn nhãn phải chịu CÙNG điều kiện với đóng.
    gblk = src[src.index("def cmd_gc("):]
    gblk = gblk[:gblk.index("\ndef ", 10)]
    i_verdict = gblk.find("closable(")
    i_label = gblk.find("set_state_labels(")
    check(i_verdict != -1 and i_label != -1 and i_verdict < i_label,
          "gc hỏi closable() TRƯỚC khi gắn nhãn",
          "gắn trước hỏi sau chính là lỗi: nhãn dính lại dù từ chối đóng")
    check("mergedAt" in gblk, "gc lấy mergedAt từ danh sách PR")
    check("KHÔNG đụng #" in gblk,
          "gc nói rõ nó BỎ QUA issue nào",
          "im lặng bỏ qua thì lần sau lại phải điều tra từ đầu")



# ─────────────────────────────────────────────────────────────────────────────
# #1639 — cổng tài liệu chạy TRƯỚC khi nhả lease


def test_pr_holds_lease_on_doc_gap():
    """`tal pr` nhả lease NGAY khi PR mở, nên bước 6 của skill `issue-work`
    (`tal docs-check <PR>` sau `tal pr`) chạy trong một worktree đã bị hook chặn
    ghi: phát hiện được khoảng trống mà không sửa được.

    Ba tính chất được ghim ở đây, và tính chất thứ ba là cái dễ vá hụt nhất:

      1. có khoảng trống theo `docsRules` ⇒ Fail, và lease VẪN GIỮ (không xoá ref,
         không ghi ledger `lease=None`);
      2. không có khoảng trống ⇒ nhả như cũ — cổng không được biến mọi PR thành
         một vòng thủ công;
      3. `--docs-ok` ⇒ nhả, NHƯNG lời khẳng định đó phải rơi vào PR để người
         review đọc được. Một cờ bỏ qua mà im lặng thì chỉ là tắt cổng.
    """
    print("cmd_pr (#1639: cổng tài liệu trước khi nhả lease)")

    for case in ("gap", "clean", "waived", "unread", "unread-waived"):
        ledger = {"issue": 88, "group": [88], "state": "work", "sub_prs": {}, "history": []}
        notes: list[str] = []
        refs_deleted: list[str] = []
        comments: list[str] = []
        released: list[str] = []

        tal.do_assert = lambda quiet=True: {"worktree": "/tmp/wt-88", "epoch": 3}
        tal.lease_file = lambda: ("/tmp/wt-88/.tal-lease.json", {
            "issue": 88, "group": [88], "branch": "issue-88",
            "keys": ["issue-88"], "session": "codr1234abcd",
        })
        tal.assert_branch_name = lambda *a, **k: None
        tal.auto_submodule_ritual = lambda wt, issue: []
        tal.submodule_audit = lambda wt, issue: []
        tal.ledger_read = lambda issue: (json.loads(json.dumps(ledger)), 1, tal.now())
        tal.ledger_write = lambda led, cid, note: notes.append((led.get("lease"), note))
        tal.ref_delete = lambda key: refs_deleted.append(key)
        tal.local_unlock = lambda key: None
        tal.mark_lease_released = lambda wt: released.append(str(wt))
        tal.set_state_labels = lambda n, s: None
        tal.issue_data = lambda n: {"title": "fix(x): y"}
        tal.with_issue_ref = lambda body, group, closes: body
        tal.warn = lambda m: None

        # Luật kích hoạt: PR chạm TaxResolver, doc thuế KHÔNG có trong diff.
        tal.docs_rules = lambda: [{
            "when": "TaxType|TaxResolver",
            "expect": ["docs/guide/tax-types.md"],
            "why": "quy ước thuế đổi mà không ai ghi lại",
        }]
        touched = ("backend/app/Services/Customer/TaxResolver.php" if case != "clean"
                   else "backend/app/Services/Foo.php")

        def files_impl(pr):
            if case.startswith("unread"):
                raise tal.DocsFilesUnavailable(
                    f"KHÔNG đọc đủ danh sách file của PR #{pr}: GitHub báo 305, nhận 300", 2
                )
            return [touched]

        tal.pr_files = files_impl

        def run_impl(args, cwd=None, check=True):
            class R:
                stdout = ("issue-88" if args[:3] == ["git", "rev-parse", "--abbrev-ref"]
                          else "1" if args[:3] == ["git", "rev-list", "--count"] else "")
            return R()

        def gh_impl(args, check=True, stdin=None):
            class R:
                stdout = ""
            if args[:2] == ["pr", "diff"]:
                R.stdout = touched
            elif args[:2] == ["pr", "create"]:
                R.stdout = "https://x/pull/900"
            elif args[:2] == ["pr", "comment"]:
                comments.append(args[-1])
            return R()

        tal.run = run_impl
        tal.gh = gh_impl
        tal.gh_json = lambda args, default=None: []

        class A:
            title = "fix(tax): x"
            body = None
            body_file = None
            allow_dirty = True
            skip_submodule_check = True
            docs_ok = (case in ("waived", "unread-waived"))

        failed = None
        try:
            tal.cmd_pr(A())
        except tal.Fail as e:
            failed = str(e)

        if case in ("gap", "unread"):
            check(failed is not None and "lease VẪN GIỮ" in failed,
                  f"{case}: cổng không kết luận → Fail và nói rõ lease còn giữ", str(failed)[:140])
            check(refs_deleted == [] and released == [],
                  f"{case}: KHÔNG nhả lease", f"deleted={refs_deleted} released={released}")
            check(all(lease is not None or note is None for lease, note in notes) or not notes,
                  f"{case}: không ghi ledger lease=None", str(notes)[:120])
        elif case == "clean":
            check(failed is None, "không có khoảng trống → không chặn", str(failed)[:140])
            check(refs_deleted == ["issue-88"] and released,
                  "không có khoảng trống → nhả lease như cũ", f"deleted={refs_deleted}")
            check(comments == [], "không có khoảng trống → không bình luận thừa vào PR", str(comments))
        elif case == "waived":
            check(failed is None, "--docs-ok → không chặn", str(failed)[:140])
            check(refs_deleted == ["issue-88"], "--docs-ok → nhả lease", str(refs_deleted))
            check(any("docs-waived" in c and "tax-types.md" in c for c in comments),
                  "--docs-ok → lời khẳng định ghi vào PR, nêu đúng luật bị bỏ qua",
                  str(comments)[:200])
        else:
            check(failed is None, "không đọc được + --docs-ok → không chặn", str(failed)[:140])
            check(refs_deleted == ["issue-88"], "không đọc được + --docs-ok → nhả lease",
                  str(refs_deleted))
            check(any("docs-unchecked" in c and "GitHub báo 305, nhận 300" in c
                      for c in comments),
                  "không đọc được + --docs-ok → ghi rõ cổng KHÔNG chạy vào PR",
                  str(comments)[:240])

        restore_tal()


def test_docs_check_and_pr_gate_share_one_measurement():
    """Hai chỗ đếm khoảng trống tài liệu phải là MỘT hàm.

    Nếu `cmd_docs_check` tự đếm riêng còn `cmd_pr` đếm kiểu khác, câu "docs-check
    đã xanh" mất nghĩa: nó xanh ở phép đo nào thì không ai biết. Test này ghim
    rằng `cmd_docs_check` gọi `docs_gate` — cùng hàm mà cổng của `tal pr` dùng.
    """
    print("docs_gate (#1639: một phép đo, hai chỗ gọi)")

    calls: list[int] = []
    real_gate = tal.docs_gate
    tal.docs_gate = lambda pr, files=None: (calls.append(pr), real_gate(pr, files))[1]
    tal.docs_rules = lambda: [{
        "when": "TaxResolver", "expect": ["docs/guide/tax-types.md"], "why": "x",
    }]

    tal.pr_files = lambda pr: ["backend/app/Services/Customer/TaxResolver.php"]

    class A:
        pr = 901
        json = True

    out = tal.cmd_docs_check(A())

    check(calls == [901], "cmd_docs_check gọi docs_gate đúng một lần", str(calls))
    gaps = [f for f in out["rules"] if f["gap"]]
    check(len(gaps) == 1 and gaps[0]["expect"] == ["docs/guide/tax-types.md"],
          "và kết quả khớp: đúng một khoảng trống, đúng file", str(out.get("rules"))[:180])

    restore_tal()


def test_pr_files_paginates_and_refuses_partial_results():
    """PR lớn phải đọc qua mọi trang; API trả thiếu vẫn phải fail-closed (#2379)."""
    print("pr_files (#2379: phân trang + đối chiếu changed_files)")

    names = [f"backend/app/F{i}.php" for i in range(305)]
    calls: list[list[str]] = []

    def gh_impl(args, check=True, stdin=None):
        calls.append(args)

        class R:
            returncode = 0
            stderr = ""
            stdout = "305" if ".changed_files" in args else "\n".join(names)

        return R()

    tal.gh = gh_impl
    out = tal.pr_files(2373)
    check(out == names, "đọc đủ 305 file, giữ nguyên thứ tự", f"nhận {len(out)}")
    file_calls = [c for c in calls if "/files?per_page=100" in " ".join(c)]
    check(len(file_calls) == 1 and "--paginate" in file_calls[0],
          "endpoint files luôn dùng --paginate + per_page=100", str(file_calls))

    def partial_impl(args, check=True, stdin=None):
        class R:
            returncode = 0
            stderr = ""
            stdout = "305" if ".changed_files" in args else "\n".join(names[:300])

        return R()

    tal.gh = partial_impl
    try:
        tal.pr_files(2373)
    except tal.DocsFilesUnavailable as e:
        check("GitHub báo 305, nhận 300" in str(e),
              "thiếu trang → fail-closed và nêu số đo hai chiều", str(e))
    else:
        check(False, "thiếu trang phải RAISE, không được trả 300 file như thể đã đủ")

    restore_tal()


# ─────────────────────────────────────────────────────────────────────────────
# Lỗi #1751 — `Ctx.repo`/`Ctx.main_worktree` suy từ cwd nên trong submodule
# `tal renew` báo SAI là mất lease
# ─────────────────────────────────────────────────────────────────────────────

def _git(*args: str) -> None:
    tal.run(["git", *args])


def _init_repo(path: Path, origin: str) -> None:
    path.mkdir(parents=True, exist_ok=True)
    _git("init", "-q", "-b", "main", str(path))
    _git("-C", str(path), "config", "user.email", "tal-test@example.com")
    _git("-C", str(path), "config", "user.name", "tal test")
    _git("-C", str(path), "config", "commit.gpgsign", "false")
    _git("-C", str(path), "remote", "add", "origin", origin)
    (path / "README.md").write_text("x\n")
    _git("-C", str(path), "add", "README.md")
    _git("-C", str(path), "commit", "-q", "-m", "init")


def test_lease_commands_agree_inside_submodule():
    """`tal renew`/`tal assert` phải cho CÙNG kết quả ở gốc worktree và trong submodule.

    #1751: hai nửa của lệnh trả lời câu "mình đang ở repo nào" theo hai cách khác
    nhau. `lease_file()` LEO CÂY từ cwd nên từ `<wt>/pos-web` vẫn thấy đúng thẻ của
    umbrella; `Ctx.repo` thì hỏi `remote.origin.url` NGAY TẠI cwd và nhận
    `godx-tempo-pos-web`. `ledger_read` do đó đi đọc sổ ở repo con — nơi không có
    issue ấy — `gh` lỗi, `gh_json(default=[])` nuốt lỗi thành sổ trắng, và
    `lease.get("session")` là None ⇒ "lease không còn thuộc session này".

    Đó là lời nói dối theo hướng nguy hiểm nhất: nó khẳng định đúng cái điều kiện
    mà skill `issue-work` bảo phải DỪNG ngay. Và nghi thức repo con thì bắt
    làm việc BÊN TRONG submodule — ai làm đúng cả hai chỉ dẫn đều gặp.

    Test dựng repo thật (umbrella + một submodule thật) chứ không giả `run`, vì cái
    sai nằm đúng ở chỗ git trả lời khác nhau tuỳ cwd — giả `run` là giả mất chính
    thứ đang đo.
    """
    print("Ctx.root + renew/assert từ trong submodule (#1751)")

    UMB, SUB = "godx-jp/godx-tempo", "godx-jp/godx-tempo-pos-web"
    ISSUE = 1751

    led = tal.ledger_new(ISSUE)
    led["state"] = "working"
    led["epoch"] = 2
    led["lease"] = {"session": "sess-A", "host": "h", "epoch": 2, "ttl": tal.TTL,
                    "keys": [f"issue-{ISSUE}"], "acquired_at": tal.iso(tal.now()),
                    "expires_at": tal.iso(tal.now())}
    comments = [{"id": 555, "body": tal.ledger_render(led),
                 "updated_at": tal.iso(tal.now())}]

    seen_paths: list[str] = []

    def fake_gh_json(args, default=None):
        # Đọc sổ CHỈ thành công ở umbrella. Repo con trả `default` — chính là hành vi
        # thật của `gh` khi issue không tồn tại ở đó (gh_json nuốt 404).
        if args and args[0] == "api" and len(args) > 1:
            seen_paths.append(args[1])
            if args[1] == f"repos/{UMB}/issues/{ISSUE}/comments":
                return [dict(c) for c in comments]
        return default

    writes: list[int | None] = []
    tal.gh_json = fake_gh_json
    # #2300: ledger_read giờ đi đường strict — stub cùng seam, vẫn ghi seen_paths
    # qua fake_gh_json để phép đo "mọi call tới repo umbrella" còn nguyên nghĩa.
    tal.gh_json_strict = lambda args, what="": (fake_gh_json(args, default=[]) or [])
    tal.ledger_write = lambda d, cid, note=None: (writes.append(cid), cid)[1]
    tal.ref_exists = lambda key: True

    class A:
        json = False

    with tempfile.TemporaryDirectory() as d:
        base = Path(d).resolve()
        sub_src = base / "pos-web-src"
        umb = base / "umbrella"
        _init_repo(sub_src, f"https://github.com/{SUB}.git")
        _init_repo(umb, f"https://github.com/{UMB}.git")
        add = tal.run(["git", "-C", str(umb), "-c", "protocol.file.allow=always",
                       "submodule", "add", "-q", str(sub_src), "pos-web"], check=False)
        if add.returncode != 0:
            check(False, "dựng được submodule thật để đo",
                  (add.stderr or add.stdout).strip()[:200])
            restore_tal()
            return
        _git("-C", str(umb), "commit", "-q", "-m", "add submodule")
        _git("-C", str(umb) + "/pos-web", "remote", "set-url", "origin",
             f"https://github.com/{SUB}.git")

        # Thẻ lease nằm ở GỐC worktree umbrella — đúng như `tal claim` ghi.
        (umb / tal.LEASE_FILE).write_text(json.dumps({
            "repo": UMB, "issue": ISSUE, "group": [ISSUE], "branch": f"issue-{ISSUE}",
            "epoch": 2, "session": "sess-A", "keys": [f"issue-{ISSUE}"],
            "comment_id": 555, "acquired_at": tal.iso(tal.now()),
        }))

        def at(cwd: Path, fn):
            old = os.getcwd()
            os.chdir(cwd)
            tal.Ctx._repo = tal.Ctx._main = tal.Ctx._root = None
            try:
                return fn()
            finally:
                os.chdir(old)
                tal.Ctx._repo = tal.Ctx._main = tal.Ctx._root = None

        inside = umb / "pos-web"
        check(inside.is_dir() and (inside / ".git").exists(),
              "sandbox: submodule đã checkout", str(inside))

        # 1. Cái sai gốc: repo suy ra được từ trong submodule.
        check(at(umb, lambda: tal.C.repo) == UMB, "gốc worktree → repo umbrella")
        got = at(inside, lambda: tal.C.repo)
        check(got == UMB, "TRONG submodule → VẪN là repo umbrella (không phải repo con)", got)

        got_wt = at(inside, lambda: str(tal.C.main_worktree))
        check(Path(got_wt).resolve() == umb, "main_worktree trong submodule → gốc umbrella", got_wt)

        # 2. Hệ quả nhìn thấy được: `tal renew` phải chạy được từ trong submodule.
        def renew():
            writes.clear()
            seen_paths.clear()
            try:
                tal.cmd_renew(A())
                return None
            except tal.Fail as e:
                return str(e)

        err_root = at(umb, renew)
        check(err_root is None and writes == [555], "renew ở gốc worktree: gia hạn được",
              str(err_root))

        err_sub = at(inside, renew)
        check(err_sub is None, "renew TRONG submodule: KHÔNG báo mất lease", str(err_sub))
        check(writes == [555], "và ghi vào đúng comment sổ (555)", str(writes))
        check(all(f"repos/{UMB}/" in p for p in seen_paths),
              "mọi lời gọi API đi tới repo umbrella, không repo con", str(seen_paths))

        # 3. Và `assert` cho CÙNG một kết quả ở hai chỗ đứng.
        def assert_():
            try:
                return tal.do_assert(quiet=True)
            except tal.Fail as e:
                return f"FAIL: {e}"

        a_root, a_sub = at(umb, assert_), at(inside, assert_)
        check(isinstance(a_root, dict), "assert ở gốc worktree: OK", str(a_root)[:160])
        # `expires_in_sec` đếm từ now() nên hai lời gọi lệch 0-1s — bỏ ra, phần còn
        # lại phải trùng khít.
        norm = [{k: v for k, v in x.items() if k != "expires_in_sec"}
                if isinstance(x, dict) else x for x in (a_root, a_sub)]
        check(norm[0] == norm[1], "assert cho cùng kết quả ở gốc và trong submodule",
              f"gốc={a_root} · sub={a_sub}")

    restore_tal()
    tal.Ctx._repo = tal.Ctx._main = tal.Ctx._root = None


def test_changed_go_modules_maps_files_to_owning_module():
    """#2339 — quy FILE đã đổi lên module Go sở hữu nó.

    `git diff --name-only` trả đường dẫn FILE. Bản trước #2339 lọc bằng
    `(tmp / path).is_dir()`, luôn False với file, nên hàm LUÔN trả rỗng và cổng
    #2156 im lặng tắt suốt từ lúc gộp monorepo — Go trôi tự do vào `dev`.

    Vẫn giữ luật cũ của #2156: chỉ module mà LÔ NÀY chạm. Đòi Go cho một lô chỉ
    có backend là biến cổng thành vật cản không liên quan.
    """
    with tempfile.TemporaryDirectory() as d:
        tmp = Path(d)
        (tmp / "workstation" / "internal" / "service").mkdir(parents=True)
        (tmp / "workstation" / "go.mod").write_text("module x\n")
        (tmp / "web" / "pos").mkdir(parents=True)          # app KHÔNG phải Go
        (tmp / "web" / "pos" / "package.json").write_text("{}\n")
        (tmp / "app" / "kds").mkdir(parents=True)          # Go nhưng lô này KHÔNG chạm
        (tmp / "app" / "kds" / "go.mod").write_text("module y\n")

        real_run = tal.run

        def fake_run(cmd, **kw):
            # Lô chạm một file Go sâu trong workstation/, một file web, một file backend.
            return types.SimpleNamespace(
                stdout=(
                    "workstation/internal/service/sync_pull.go\n"
                    "web/pos/src/main.tsx\n"
                    "backend/app/Foo.php\n"
                ),
                stderr="", returncode=0,
            )

        tal.run = fake_run
        try:
            got = tal.changed_go_modules(tmp)
        finally:
            tal.run = real_run

        assert got == ["workstation"], (
            "file Go sâu trong cây phải quy về module sở hữu; có %r" % (got,)
        )


def test_changed_go_modules_picks_the_innermost_module():
    """Module lồng nhau: cái TRONG CÙNG mới là chủ sở hữu file.

    Quy lên tổ tiên đầu tiên có `go.mod`, không phải lên module ngoài — nếu
    không, một thay đổi ở module con sẽ đi kiểm nhầm module cha.
    """
    with tempfile.TemporaryDirectory() as d:
        tmp = Path(d)
        (tmp / "outer" / "inner" / "pkg").mkdir(parents=True)
        (tmp / "outer" / "go.mod").write_text("module outer\n")
        (tmp / "outer" / "inner" / "go.mod").write_text("module inner\n")

        real_run = tal.run
        tal.run = lambda cmd, **kw: types.SimpleNamespace(
            stdout="outer/inner/pkg/a.go\n", stderr="", returncode=0,
        )
        try:
            got = tal.changed_go_modules(tmp)
        finally:
            tal.run = real_run

        assert got == ["outer/inner"], "phải là module trong cùng; có %r" % (got,)

def test_gate_go_modules_noop_when_lot_touches_no_go_submodule():
    """Lô không chạm submodule Go nào ⇒ cổng im lặng đi qua, KHÔNG đòi `go`."""
    with tempfile.TemporaryDirectory() as d:
        tmp = Path(d)
        real_run, real_which = tal.run, tal.shutil.which

        tal.run = lambda cmd, **kw: types.SimpleNamespace(
            stdout="backend/app/Foo.php\n", stderr="", returncode=0)
        # Nếu cổng đòi `go` ở đây thì nó sai — dựng `which` luôn trả None để lộ ra.
        tal.shutil.which = lambda _n: None
        try:
            tal.gate_go_modules(tmp)   # không được ném
        finally:
            tal.run, tal.shutil.which = real_run, real_which


def test_gate_go_modules_refuses_when_go_is_missing():
    """#2156 — lô CHẠM submodule Go mà máy không có `go` ⇒ CỔNG HỎNG, không bỏ qua.

    Bỏ qua im lặng là đúng cái lỗ issue này sinh ra để bịt: bốn cổng cùng nói xanh
    trong khi không cổng nào thật sự nhìn. "Chưa nhìn" KHÔNG phải "sạch".
    """
    with tempfile.TemporaryDirectory() as d:
        tmp = Path(d)
        (tmp / "workstation-app").mkdir()
        (tmp / "workstation-app" / "go.mod").write_text("module x\n")

        real_run, real_which = tal.run, tal.shutil.which
        tal.run = lambda cmd, **kw: types.SimpleNamespace(
            stdout="workstation-app/main.go\n", stderr="", returncode=0)
        tal.shutil.which = lambda _n: None
        try:
            tal.gate_go_modules(tmp)
        except tal.Fail as e:
            assert e.code == tal.GATE_BROKEN, (
                "thiếu công cụ là CỔNG HỎNG (%d), không phải test đỏ; có %d"
                % (tal.GATE_BROKEN, e.code))
        else:
            raise AssertionError(
                "cổng ĐI QUA khi không có `go` — lô dời con trỏ submodule Go mà "
                "không ai kiểm nó biên dịch được (#2156)")
        finally:
            tal.run, tal.shutil.which = real_run, real_which


def _tiny_go_module(root: Path, *, embed_missing_dir: bool, broken: bool) -> None:
    """Dựng một module Go tí hon để cổng chạy `go` THẬT lên nó."""
    root.mkdir(parents=True, exist_ok=True)
    (root / "go.mod").write_text("module tinygate\n\ngo 1.21\n")
    embed = ""
    if embed_missing_dir:
        # y hệt workstation-app/frontend.go: trỏ vào thư mục artifact KHÔNG có trong git
        embed = ('import "embed"\n\n//go:embed all:frontend/dist\n'
                 'var assets embed.FS\n\nvar _ = assets\n')
    body = "func Hello() int { return 1 }\n" if not broken else "func Hello() int { return }\n"
    (root / "main.go").write_text("package tinygate\n\n" + embed + "\n" + body)


def test_gate_go_modules_compiles_a_REAL_module_with_missing_embed_dir():
    """#2156 vòng 2 — ba ca đầu MOCK mất `go`, nên không ca nào chứng minh cổng CHẠY được.

    Reviewer đo và chỉ đúng chỗ: `tal.run` bị thay ⇒ `run_stage` không bao giờ gọi
    `go build`; `shutil.which` bị thay ⇒ nhánh "có go" không bao giờ đi tới. Ba ca
    ấy ghim logic CHỌN submodule — đúng, và vẫn giữ — nhưng cổng vẫn có thể đỏ 100%
    mà chúng không hay biết. Đây là ca duy nhất phân biệt "cổng hoạt động" với
    "cổng được mock".

    Module dựng ra mang đúng bệnh của workstation-app: `//go:embed all:frontend/dist`
    trỏ vào một thư mục artifact không tồn tại (#1197).
    """
    if tal.shutil.which("go") is None:
        print("  BỎ QUA test_gate_..._REAL_module — máy này không có `go`")
        return
    with tempfile.TemporaryDirectory() as d:
        tmp = Path(d)
        _tiny_go_module(tmp / "wsapp", embed_missing_dir=True, broken=False)

        real_changed = tal.changed_go_modules
        tal.changed_go_modules = lambda _t: ["wsapp"]
        try:
            tal.gate_go_modules(tmp)   # KHÔNG được ném
        except tal.Fail as e:
            raise AssertionError(
                "cổng ĐỎ trên một module Go HỢP LỆ, chỉ vì thiếu thư mục artifact của "
                "//go:embed — mọi lô bump con trỏ workstation-app sẽ không bao giờ "
                "merge được (#2156 vòng 2): %s" % e) from None
        finally:
            tal.changed_go_modules = real_changed

        assert (tmp / "wsapp" / "frontend" / "dist" / "gate-stub.txt").exists(), (
            "cổng qua được nhưng KHÔNG dựng stub — nó qua vì lý do khác, và lần sau "
            "đổi go:embed là đỏ lại")


def test_gate_go_modules_still_catches_REALLY_broken_go():
    """Đối trọng: dựng stub KHÔNG được biến cổng thành cái luôn xanh.

    Không có ca này thì `stub_missing_embed_dirs` có thể vô hiệu hoá cả cổng mà ca
    trước vẫn xanh — đúng loại "sửa cho hết đỏ" mà cổng sinh ra để chặn.
    """
    if tal.shutil.which("go") is None:
        print("  BỎ QUA test_gate_..._REALLY_broken — máy này không có `go`")
        return
    with tempfile.TemporaryDirectory() as d:
        tmp = Path(d)
        _tiny_go_module(tmp / "wsapp", embed_missing_dir=True, broken=True)

        real_changed = tal.changed_go_modules
        tal.changed_go_modules = lambda _t: ["wsapp"]
        try:
            tal.gate_go_modules(tmp)
        except tal.Fail as e:
            assert e.code == 2, (
                "Go hỏng phải là mã 2 (đỏ thật), không phải CỔNG HỎNG; có %d" % e.code)
        else:
            raise AssertionError(
                "cổng ĐI QUA một module Go KHÔNG biên dịch được — stub đã nuốt luôn "
                "cái nó phải bắt (#2156)")
        finally:
            tal.changed_go_modules = real_changed


def test_stub_missing_embed_dirs_leaves_a_FILE_pattern_alone():
    """Pattern trỏ tới FILE mà thiếu ⇒ KHÔNG dựng — đó là file đáng lẽ nằm trong git.

    Dựng bừa một file rỗng ở đó là biến một lỗi thật (quên commit tài nguyên) thành
    bản build xanh mang tài nguyên rỗng.
    """
    with tempfile.TemporaryDirectory() as d:
        root = Path(d)
        (root / "go.mod").write_text("module x\n")
        (root / "a.go").write_text(
            'package x\n\nimport "embed"\n\n//go:embed banner.txt\nvar b embed.FS\n')
        made = tal.stub_missing_embed_dirs(root)
        assert made == [], "pattern trỏ tới FILE không được dựng stub; có %r" % (made,)
        assert not (root / "banner.txt").exists()


# Số ca test tối thiểu — chốt lúc bỏ tuple liệt-kê-tay (#2202), khi tuple có
# ĐÚNG 50 tên. Đây là rào CHỐNG TỤT, không phải con số phải bảo trì: thêm test
# thì nó tự vượt qua và không ai phải sửa gì. Nó chỉ nổ khi số ca phát hiện được
# TỤT XUỐNG — tức là hoặc có người xoá test, hoặc `discover_tests()` đã ngừng
# nhìn thấy chúng (một `if __name__` bị dời lên giữa file làm mọi def phía sau
# biến mất khỏi `globals()` mà không có lỗi nào). Cả hai đều phải nói ra miệng.
MIN_TESTS = 50


def discover_tests() -> list:
    """Mọi hàm `test_*` ở cấp module — đọc `globals()` TẠI THỜI ĐIỂM GỌI.

    Vì sao không liệt kê tay (#2202): `main()` từng lặp trên một tuple tên viết
    tường minh, nên một hàm `test_*` mới viết xong **không bao giờ chạy** mà suite
    vẫn in "tất cả pass". Đã cắn thật ở #2156 — nghi thức chiều ngược (gỡ bản sửa,
    đòi test ĐỎ) báo XANH cả hai chiều vì ba ca mới nằm ngoài tuple. Một bài test
    tồn tại, trông như đã canh, và không bao giờ nổ thì TỆ HƠN không có test: nó
    trả lời "rồi" cho câu hỏi "chỗ này canh chưa?".

    Bẫy thứ hai, cùng ca: các định nghĩa nằm SAU khối `if __name__ == "__main__"`
    thì chưa tồn tại lúc `main()` chạy. Ở đây tránh được vì `globals()` được đọc
    lúc GỌI, và lời gọi duy nhất nằm ở dòng CUỐI file — mọi `def` đã xong. Đừng
    dời khối `if __name__` lên giữa file; `MIN_TESTS` ở trên là cái sẽ bắt.
    """
    return [fn for name, fn in sorted(globals().items())
            if name.startswith("test_") and isinstance(fn, types.FunctionType)]


def main() -> int:
    tests = discover_tests()
    print(f"phát hiện {len(tests)} ca test (ngưỡng tối thiểu {MIN_TESTS})\n")
    if len(tests) < MIN_TESTS:
        print(f"HỎNG: chỉ phát hiện {len(tests)} ca test, kỳ vọng ít nhất {MIN_TESTS}. "
              f"Test đã bị xoá, hoặc `discover_tests()` không còn nhìn thấy chúng "
              f"(khối `if __name__` bị dời lên trước các `def`?). Suite này là cổng "
              f"của chính vòng lặp issue — không được im lặng co lại (#2202).")
        return 1
    for fn in tests:
        restore_tal()          # mỗi test bắt đầu từ `tal` NGUYÊN BẢN, không nhận rác của test trước
        fn()
        print()
    if FAILURES:
        print(f"{len(FAILURES)} FAIL: " + ", ".join(FAILURES))
        return 1
    print(f"tất cả pass ({len(tests)} ca)")
    return 0


# ─────────────────────────────────────────────────────────────────────────────
# #2151 — "không đo được" KHÁC "không có gì"
# ─────────────────────────────────────────────────────────────────────────────

def test_queue_refuses_to_call_it_empty_when_it_could_not_measure():
    print("cmd_queue (gh hỏng ⇒ RAISE, không in 'hàng đợi rỗng')")

    # Đúng hình dạng đã tái hiện được: hạn mức GraphQL cạn, `gh` thoát 1, và
    # `gh_json(default=[])` biến nó thành danh sách rỗng ⇒ tal in câu trấn an
    # kèm exit 0 trong khi ba issue `agent:ready` vẫn mở.
    def gh_rate_limited(args, check=True, stdin=None):
        class R:
            stdout = ""
            stderr = "GraphQL: API rate limit already exceeded for user ID 26842626."
            returncode = 1
        return R()

    tal.gh = gh_rate_limited
    tal.refs_all = lambda: []

    class A:
        json = False
        verbose = False
        limit = 10

    try:
        tal.cmd_queue(A())
    except tal.Fail as e:
        msg = str(e)
        check("KHÔNG ĐO ĐƯỢC" in msg, "nêu rõ KHÔNG ĐO ĐƯỢC", msg)
        check("rate limit" in msg, "mang theo lỗi thật của gh", msg)
        check("rate_limit" in msg, "gợi ý chỗ kiểm quota", msg)
    else:
        check(False, "phải RAISE thay vì báo hàng đợi rỗng")


def test_queue_still_reports_a_genuinely_empty_backlog():
    print("cmd_queue (gh trả [] thật ⇒ vẫn là 'hàng đợi rỗng')")

    # Mặt kia của bánh cóc: bản sửa KHÔNG được biến một backlog rỗng thật thành
    # lỗi — nếu không thì vòng lặp kêu ầm mỗi khi thực sự hết việc.
    def gh_empty(args, check=True, stdin=None):
        class R:
            stdout = "[]"
            stderr = ""
            returncode = 0
        return R()

    tal.gh = gh_empty
    tal.refs_all = lambda: []

    class A:
        json = True
        verbose = False
        limit = 10

    out = tal.cmd_queue(A())
    check(out["eligible"] == [], "đo được và trả về rỗng, không ném")


# ─────────────────────────────────────────────────────────────────────────────
# #2238 — mất THẺ không phải mất LEASE
# ─────────────────────────────────────────────────────────────────────────────


class _AdoptArgs:
    def __init__(self, issue=None, as_json=True):
        self.issue = issue
        self.json = as_json


def _adopt_world(td, owner, epoch=7, ref_alive=True):
    """Dựng một thế giới nhỏ cho `cmd_adopt`: sổ + ref + worktree, không mạng."""
    root = Path(td)
    wt = root / ".claude" / "worktrees" / "issue-2238"
    wt.mkdir(parents=True)
    state = root / "state"
    state.mkdir()

    class FakeCtx:
        repo = "o/r"
        main_worktree = root
        worktrees_dir = root / ".claude" / "worktrees"
        state_dir = state

    tal.C = FakeCtx()

    led = {"issue": 2238, "group": [2238], "branch": "issue-2238", "state": "executing",
           "epoch": epoch, "attempts": 1, "review_rounds": 0, "reaps": 0, "pr": None,
           "history": [],
           "lease": {"key": "issue-2238", "keys": ["issue-2238"], "epoch": epoch,
                     "session": owner, "host": "h", "ttl": 2700,
                     "acquired_at": "2026-08-09T00:00:00Z",
                     "expires_at": "2026-08-09T00:45:00Z"}}

    writes: list = []
    server_clock = tal.now()          # sổ trả đồng hồ SERVER, không phải chuỗi
    tal.ledger_read = lambda n: (led, 5226320939, server_clock)
    tal.ledger_write = lambda d, cid, note=None: writes.append(note) or cid
    tal.lease_expired = lambda d, upd: False
    tal.ref_exists = lambda k: ref_alive
    tal.refs_all = lambda: ["issue-2238", "pr-9"]
    tal.local_lock = lambda k: True
    tal.ensure_worktree = lambda issue, branch: wt
    tal.assert_worktree_attached = lambda p: None
    return wt, led, writes


def test_adopt_restores_the_card_without_bumping_epoch():
    """#2238 — thẻ mất trong khi lease vẫn sống phải có đường về KHÔNG phá gì."""
    print("cmd_adopt: dựng lại thẻ từ sổ, epoch GIỮ NGUYÊN (#2238)")

    with tempfile.TemporaryDirectory() as td:
        me = tal.session_id()
        wt, led, writes = _adopt_world(td, owner=me, epoch=7)
        card = wt / tal.LEASE_FILE

        # Trạng thái sự cố: lease sống trên ref + sổ, nhưng thẻ đã mất.
        check(not card.exists(), "xuất phát: không có thẻ trên đĩa")
        try:
            tal.lease_file(wt, search=False)
            check(False, "lease_file phải Fail khi mất thẻ")
        except tal.Fail as e:
            check(e.code == 3, "lease_file vẫn Fail(…, 3) như cũ", str(e.code))
            check("tal adopt" in str(e), "thông điệp CHỈ ra đường phục hồi, không đẩy về claim", str(e))

        out = tal.cmd_adopt(_AdoptArgs())          # không truyền issue → phải tự suy ra

        check(out["issue"] == 2238, "suy đúng issue từ sổ khi không đứng trong worktree", str(out))
        check(card.is_file(), "thẻ đã được dựng lại")
        got = json.loads(card.read_text())
        check(got["epoch"] == 7, "epoch GIỮ NGUYÊN — không bump", str(got.get("epoch")))
        check(led["epoch"] == 7, "sổ cũng không bị bump", str(led["epoch"]))
        check(got["session"] == me, "thẻ ghi đúng session đang chạy")
        check(got["comment_id"] == 5226320939, "thẻ mang comment_id của sổ", str(got))
        check(writes == [], "KHÔNG ghi gì lên sổ (ghi = lặng lẽ gia hạn lease)", str(writes))

        # Và cái quan trọng: sau adopt thì cổng ghi mở lại, đúng epoch cũ.
        tal.lease_file = lambda start=None, search=True: (wt, json.loads(card.read_text()))
        st = tal.do_assert(quiet=True)
        check(st["epoch"] == 7, "do_assert xanh với ĐÚNG epoch cũ", str(st))


def test_adopt_refuses_a_lease_that_belongs_to_another_session():
    """Bài quan trọng nhất: không có nó thì `adopt` chính là cửa sau."""
    print("cmd_adopt: TỪ CHỐI lease của session khác (#2238)")

    with tempfile.TemporaryDirectory() as td:
        wt, led, writes = _adopt_world(td, owner="SESSION-CUA-NGUOI-KHAC", epoch=4)
        card = wt / tal.LEASE_FILE

        try:
            tal.cmd_adopt(_AdoptArgs(issue=2238))
            check(False, "phải TỪ CHỐI, không được dựng thẻ")
        except tal.Fail as e:
            check(e.code == 5, "thoát bằng mã tách-vai (5), không phải 'không thấy thẻ' (3)", str(e.code))
            check("KHÔNG phải bạn" in str(e), "nói rõ lease là của người khác", str(e))
            check("cần người" in str(e), "chỉ ra đây là ca cần người, không tự xử", str(e))

        check(not card.exists(), "KHÔNG có thẻ nào được ghi ra")
        check(led["epoch"] == 4, "sổ không bị đụng vào", str(led["epoch"]))
        check(writes == [], "không ghi gì lên sổ", str(writes))


def test_adopt_refuses_when_the_lease_ref_is_already_gone():
    """Sổ nói của mình nhưng ref đã mất = lease đã bị thu hồi. Dựng thẻ là dựng ảo giác."""
    print("cmd_adopt: TỪ CHỐI khi ref CAS đã chết (#2238)")

    with tempfile.TemporaryDirectory() as td:
        wt, led, writes = _adopt_world(td, owner=tal.session_id(), epoch=2, ref_alive=False)
        card = wt / tal.LEASE_FILE

        try:
            tal.cmd_adopt(_AdoptArgs(issue=2238))
            check(False, "phải TỪ CHỐI khi ref không còn")
        except tal.Fail as e:
            check("ref issue-2238 KHÔNG còn" in str(e), "nêu đúng thứ đã mất", str(e))
            check("tal claim 2238" in str(e), "đẩy về claim — ca này epoch PHẢI tăng", str(e))

        check(not card.exists(), "KHÔNG có thẻ nào được ghi ra")
        check(writes == [], "không ghi gì lên sổ", str(writes))


# ─────────────────────────────────────────────────────────────────────────────
# #2202 — một ca test mới viết PHẢI chạy, không cần ai đăng ký nó ở đâu cả
# ─────────────────────────────────────────────────────────────────────────────

def test_a_newly_added_test_actually_runs():
    """#2202 — rào cho chính cái rào: suite phải tự phát hiện test.

    Không kiểm bằng cách đọc `main()` (ghim văn bản thì đổi cách viết là hỏng),
    mà bằng cách LÀM đúng việc đã cắn ở #2156: thêm một ca `test_*` mới vào cuối
    danh sách rồi đòi suite nổ vì nó. Bản tuple-liệt-kê-tay cho ra XANH ở đây —
    đó chính là lỗi.
    """
    print("main(): ca test mới thêm được CHẠY, không cần đăng ký (#2202)")

    import ast
    import subprocess

    src = Path(__file__).read_text()

    # (a) khối `if __name__` phải là câu lệnh CUỐI CÙNG ở cấp module. Bẫy thứ hai
    #     của #2156: hàm định nghĩa SAU khối đó chưa tồn tại lúc `main()` chạy —
    #     `sys.exit` đã cắt ngang. Tự phát hiện không cứu được ca này, chỉ thứ tự
    #     trong file mới cứu, nên ghim thứ tự.
    tree = ast.parse(src)
    last = tree.body[-1]
    check(isinstance(last, ast.If) and "__main__" in ast.dump(last.test),
          "khối `if __name__` là câu lệnh CUỐI file — def sau nó sẽ không kịp tồn tại",
          type(last).__name__)

    # (b) discover_tests() thấy ĐÚNG tập `def test_*` cấp module trong file nguồn.
    in_source = {n.name for n in tree.body
                 if isinstance(n, ast.FunctionDef) and n.name.startswith("test_")}
    discovered = {fn.__name__ for fn in discover_tests()}
    check(discovered == in_source,
          f"phát hiện đủ {len(in_source)} ca có trong file nguồn",
          f"thiếu={sorted(in_source - discovered)} thừa={sorted(discovered - in_source)}")
    check(len(in_source) >= MIN_TESTS,
          f"số ca ({len(in_source)}) không tụt dưới ngưỡng MIN_TESTS={MIN_TESTS}")

    if os.environ.get("TAL_TEST_NO_SPAWN"):
        print("  ·    (bản con: bỏ qua bước sinh tiến trình để khỏi đệ quy)")
        return

    # (c) phép đo thật: chạy một BẢN SAO của chính file này, có nhét thêm một ca
    #     test hỏng ngay trước khối `if __name__` — đúng chỗ người ta thêm test.
    #     Suite con PHẢI đỏ và PHẢI gọi tên nó.
    marker = "CANARY_2202_DA_CHAY"
    canary = (f'def test_zzz_canary_2202():\n'
              f'    print("canary #2202")\n'
              f'    check(False, "{marker}")\n\n\n')
    # Lần xuất hiện CUỐI mới là khối thật — chuỗi này còn nằm trong chính ca test
    # đang đọc, nên `replace` sẽ nhét canary vào giữa một literal và làm hỏng file.
    anchor = 'if __name__ == "__main__":'
    head, tail = src.rsplit(anchor, 1)
    child_src = head + canary + anchor + tail
    # +1 chứ không phải ==1: tên canary đã xuất hiện sẵn trong file này (nó nằm
    # trong chính chuỗi đang dựng ở trên), nên phép đo là "nhiều hơn bản gốc một".
    check(child_src.count("def test_zzz_canary_2202")
          == src.count("def test_zzz_canary_2202") + 1,
          "chèn đúng MỘT ca canary, ngay trước khối `if __name__` cuối file")

    with tempfile.TemporaryDirectory() as td:
        child = Path(td) / "tal_test_child.py"
        child.write_text(child_src)
        os.symlink(HERE / "tal", Path(td) / "tal")   # load_tal() đọc `tal` cạnh file
        env = dict(os.environ, TAL_TEST_NO_SPAWN="1")
        p = subprocess.run([sys.executable, str(child)], capture_output=True, text=True, env=env)

    out = p.stdout + p.stderr
    # MỘT phép đo, hai điều kiện cùng lúc — cố ý. Tách ra thì "đỏ" một mình đậu
    # được vì lý do khác (suite con đỏ ở ca khác) và cho cảm giác đã canh.
    check(p.returncode == 1 and marker in out,
          "suite con ĐỎ và gọi ĐÚNG TÊN ca mới thêm — tức là ca đó đã thật sự chạy",
          f"returncode={p.returncode}; có marker={marker in out}; đuôi output: {out[-400:]}")


# ─────────────────────────────────────────────────────────────────────────────
# #2201 — XÁC worktree khác CÂY BẨN; đừng đọc trạng thái cây chính rồi đổ cho nó
# ─────────────────────────────────────────────────────────────────────────────

def test_gc_calls_a_worktree_corpse_a_corpse_not_uncommitted_work():
    """#2201 — gc không được báo "còn thay đổi chưa commit" từ dữ liệu CÂY CHÍNH.

    Fixture git THẬT, không mock `run`: thứ cần chứng minh là hành vi của chính
    `git status` khi đứng trong một thư mục mà đăng ký worktree không còn — nó im
    lặng giải lên repo cha và trả về cái bẩn của CÂY CHÍNH. Đó là lý do gc in
    đúng "18 file" cho cả 18 xác và không bao giờ dọn được cái nào.
    """
    print("cmd_gc: xác worktree bị gọi là XÁC, không phải 'chưa commit' (#2201)")

    import subprocess

    with tempfile.TemporaryDirectory() as td:
        root = Path(td) / "repo"
        root.mkdir()
        subprocess.run(["git", "init", "-q", str(root)], check=True)
        # cây chính BẨN — đúng như thật (vendor/, .agents/, skills-lock.json…)
        (root / "vendor").mkdir()
        (root / "vendor" / "x.txt").write_text("rác\n")
        (root / "skills-lock.json").write_text("{}\n")

        wts = root / ".claude" / "worktrees"
        corpse = wts / "issue-77"
        corpse.mkdir(parents=True)             # thư mục CÒN, đăng ký git KHÔNG có

        # Kiểm chứng cái bẫy có thật trước khi kiểm bản sửa: `git status` từ trong
        # xác trả về đồ của cây chính, chứ không phải rỗng.
        leaked = subprocess.run(["git", "status", "--porcelain"], cwd=str(corpse),
                                capture_output=True, text=True).stdout.strip()
        check(bool(leaked),
              "bẫy có thật: `git status` trong xác đọc được trạng thái bẩn của CÂY CHÍNH",
              repr(leaked))

        state = root / "state"; state.mkdir()

        class FakeCtx:
            repo = "o/r"
            main_worktree = root
            worktrees_dir = wts
            state_dir = state
        tal.C = FakeCtx()

        def fake_gh_json(args, default=None):
            if args[:2] == ["pr", "list"] and "merged" in args:
                return [{"number": 900, "headRefName": "issue-77",
                         "closingIssuesReferences": [], "mergedAt": "2026-08-08T00:00:00Z"}]
            return [] if args[:2] == ["pr", "list"] else default

        tal.gh_json = fake_gh_json
        tal.gh = lambda args, check=True, stdin=None: None
        tal.reap_batch_gate = lambda dry: []
        tal.reap_leases = lambda dry: []
        tal.delete_merged_branches = lambda repo, dry, protect: []
        tal.submodules = lambda: {}
        tal.live_lease_issues = lambda: set()
        tal.open_issues = lambda: []            # #77 đã đóng — chỉ còn việc dọn cục bộ
        tal.branch_exists_local = lambda b, cwd=None: False
        tal.branch_exists_remote = lambda b, cwd=None: False

        class A:
            dry_run = False
            no_submodules = True
            include_abandoned = False
            json = False

        acts = tal.cmd_gc(A())

        # Phải hỏi TRONG `with`: ra khỏi khối là tempdir bị xoá, và khi đó
        # `not corpse.exists()` đúng vô điều kiện — một phép đo xanh mà không đo gì.
        check(not corpse.exists(), "xác đã được dọn — gc hội tụ thay vì lặp lại mãi",
              f"{corpse} vẫn còn")

    said = " | ".join(a["action"] for a in acts if a.get("issue") == 77)
    check("chưa commit" not in said,
          "KHÔNG bịa 'còn thay đổi chưa commit' từ trạng thái cây chính", said or "(rỗng)")
    check("XÁC" in said, "gọi thẳng tên: đây là XÁC worktree", said or "(rỗng)")


# ─────────────────────────────────────────────────────────────────────────────
# #2270 — lease theo VÙNG FILE: hai issue khác nhau, cùng file
# ─────────────────────────────────────────────────────────────────────────────

def _region_world(holders: dict[int, dict]):
    """Dựng thế giới giả cho rào vùng: `refs_all` + `ledger_read` + đồng hồ.

    `holders` = {issue: {"session":…, "regions":[…], "expired": bool}}. Đi đúng
    hai hàm mà bản thật đọc, nên test đo hành vi chứ không đo lại chính nó.
    """
    tal.refs_all = lambda: [f"issue-{n}" for n in holders] + ["pr-99", "merge-batch"]

    def _read(n):
        h = holders.get(n)
        if h is None:
            return {"issue": n, "lease": None}, 1, tal.now()
        return ({"issue": n, "lease": {"session": h["session"], "ttl": 2700,
                                       "host": "box", "regions": h["regions"]}},
                1, tal.now())

    tal.ledger_read = _read
    tal.lease_expired = lambda d, upd: bool(
        (holders.get(d.get("issue"), {}) or {}).get("expired"))


def test_regions_overlap_compares_path_COMPONENTS_not_strings():
    """`backend/app2` KHÔNG nằm trong `backend/app` — dù chuỗi nói ngược lại.

    `"backend/app2".startswith("backend/app")` là True, nên phép so chuỗi thô
    chặn oan một session chẳng đụng gì tới vùng kia. Chặn oan không vô hại: nó
    dạy người vận hành gõ `--force` theo phản xạ, và từ đó rào chỉ còn là trang trí.
    """
    print("regions_overlap: so theo THÀNH PHẦN đường dẫn (#2270)")

    check(tal.regions_overlap("backend/app", "backend/app/Services") is True,
          "cha chứa con → chồng")
    check(tal.regions_overlap("backend/app/Services", "backend/app") is True,
          "con nằm trong cha → chồng (đối chiếu HAI CHIỀU)")
    check(tal.regions_overlap("backend/app", "backend/app") is True,
          "trùng khít → chồng")
    check(tal.regions_overlap("backend/app", "backend/app2") is False,
          "`backend/app2` KHÔNG chồng `backend/app`",
          "đây là bẫy so chuỗi thô: startswith() nói True")
    check(tal.regions_overlap("backend/app2", "backend/app") is False,
          "chiều ngược lại cũng KHÔNG chồng")
    check(tal.regions_overlap("backend/app", "admin-web/src") is False,
          "hai cây khác nhau → không chồng")
    check(tal.regions_overlap("./backend/app/", "backend//app/Services") is True,
          "chuẩn hoá `./` và `//` trước khi so, không phải khi in")


def test_claim_refuses_a_region_another_live_lease_holds():
    """Hai session, hai issue KHÁC NHAU, cùng vùng file — đúng khe mà #2270 vá."""
    print("guard_regions: vùng chồng lấn ⇒ TỪ CHỐI, và nói rõ ai giữ (#2270)")

    _region_world({4242: {"session": "OTHER999-bbbb",
                          "regions": ["backend/app/Services"], "expired": False}})

    try:
        tal.guard_regions(["backend/app"], exclude={4243}, force=False)
        check(False, "claim vào vùng người khác đang giữ phải bị chặn", "không raise")
    except tal.Fail as e:
        msg = str(e)
        check(e.code == tal.BUSY,
              "mã thoát là BUSY (người khác đang giữ), không phải lỗi thật",
              f"code={e.code}")
        check("#4242" in msg, "nêu ĐÚNG issue đang giữ", msg)
        check("OTHER999" in msg, "nêu ĐÚNG session đang giữ", msg)
        check("backend/app/Services" in msg, "nêu ĐÚNG vùng đang bị giữ", msg)
        check("còn" in msg, "nói còn bao lâu nữa mới nhả", msg)

    # Không chồng thì đi qua, và trả về vùng đã chuẩn hoá để ghi vào lease.
    got = tal.guard_regions(["./admin-web/src/"], exclude={4243}, force=False)
    check(got == ["admin-web/src"], "vùng không chồng → cho qua + chuẩn hoá", str(got))

    # Bẫy chuỗi thô, đo xuyên qua cả đường thật chứ không chỉ hàm so sánh.
    # Bọc try: phép so cùn thì đây RAISE, và một exception lọt ra ngoài sẽ giết
    # cả suite giữa chừng — mọi ca sau đó không chạy nữa. Muốn mutation cho một
    # dòng FAIL có TÊN, không phải một traceback nuốt mất phần còn lại.
    _region_world({4242: {"session": "OTHER999-bbbb",
                          "regions": ["backend/app"], "expired": False}})
    try:
        got = tal.guard_regions(["backend/app2"], exclude={4243}, force=False)
    except tal.Fail as e:
        got = f"BỊ CHẶN OAN: {e}"
    check(got == ["backend/app2"],
          "`backend/app2` KHÔNG bị `backend/app` chặn",
          str(got))


def test_claim_region_force_passes_but_shouts():
    """`--force` vượt được — nhưng im lặng vượt thì rào coi như không có."""
    print("guard_regions: --force vượt + CẢNH BÁO (#2270)")

    _region_world({4242: {"session": "OTHER999-bbbb",
                          "regions": ["backend/app"], "expired": False}})

    said: list[str] = []
    tal.warn = lambda m: said.append(m)

    got = tal.guard_regions(["backend/app/Services"], exclude={4243}, force=True)
    check(got == ["backend/app/Services"], "--force vẫn claim được", str(got))
    blob = "\n".join(said)
    check(len(said) == 1, "có đúng một cảnh báo", str(said))
    check("force" in blob.lower() and "#4242" in blob,
          "cảnh báo nêu là đang VƯỢT rào và ai đang giữ", blob)

    # #2300 A19 — vượt rào VÙNG là quyết định RIÊNG (`--force-region`), không đi
    # kèm miễn phí với `--force` (cờ đó chỉ nói "nhặt issue chưa-ready hộ người"):
    # force để nhặt issue từng lặng lẽ đè luôn vùng file của session khác.
    src = (HERE / "tal").read_text()
    blk = src[src.index("def cmd_claim("):]
    blk = blk[:blk.index("\ndef ", 10)]
    check("guard_regions(" in blk, "cmd_claim thật sự gọi rào vùng")
    call = blk[blk.index("guard_regions("):][:220]
    check("force_region" in call and "force=a.force," not in call and not call.strip().endswith("force=a.force)"),
          "rào vùng nhận cờ RIÊNG --force-region, không ăn theo --force", call)
    check(blk.index("guard_regions(") < blk.index("ref_create("),
          "rào chạy TRƯỚC khi tạo ref — chặn ở đây thì không có gì phải rollback")


def test_no_region_declared_blocks_nobody():
    """Tương thích cũ: mọi lease đang chạy hôm nay đều KHÔNG có khoá `regions`."""
    print("guard_regions: không khai vùng ⇒ không giữ, không bị chặn (#2270)")

    # (1) người xin không khai vùng → qua, dù người khác giữ cả cây.
    _region_world({4242: {"session": "OTHER999-bbbb",
                          "regions": ["backend"], "expired": False}})
    check(tal.guard_regions([], exclude={4243}, force=False) == [],
          "claim không --region → không bị chặn bởi bất kỳ ai")

    # (2) lease ĐANG SỐNG của bản cũ (sổ không có khoá `regions`) không giữ gì.
    tal.refs_all = lambda: ["issue-4242"]
    tal.ledger_read = lambda n: (
        {"issue": n, "lease": {"session": "OLD-SESSION", "ttl": 2700}}, 1, tal.now())
    tal.lease_expired = lambda d, upd: False
    check(tal.live_region_holders() == [],
          "lease bản cũ (không có khoá `regions`) không giữ vùng nào")
    check(tal.guard_regions(["backend/app"], force=False) == ["backend/app"],
          "nên nó KHÔNG chặn được session mới có khai vùng",
          "nếu chặn thì mọi lease đang chạy lúc deploy sẽ khoá cứng cả repo")


def test_expired_lease_releases_its_regions():
    """Vùng không có vòng đời riêng — nó đi theo lease. Hết hạn ⇒ nhả."""
    print("live_region_holders: lease hết hạn ⇒ vùng tự nhả (#2270)")

    _region_world({4242: {"session": "DEAD1111", "regions": ["backend/app"],
                          "expired": True}})
    check(tal.live_region_holders() == [], "lease quá TTL không còn giữ vùng")
    check(tal.guard_regions(["backend/app"], exclude={4243}, force=False)
          == ["backend/app"],
          "vùng của lease chết claim lại được ngay, không cần lệnh dọn riêng")

    # Ref biến mất (gc đã thu hồi) cũng vậy — chỉ cần một đường, không hai.
    _region_world({})
    check(tal.guard_regions(["backend/app"], force=False) == ["backend/app"],
          "ref bị gc xoá ⇒ vùng nhả theo, không còn dấu vết")

    # Và vùng phải nằm TRONG payload lease, chứ không phải một kênh riêng —
    # kênh riêng thì có trạng thái riêng, và trạng thái riêng thì rò.
    src = (HERE / "tal").read_text()
    blk = src[src.index("def cmd_claim("):]
    blk = blk[:blk.index("\ndef ", 10)]
    check('"regions": regions' in blk, "claim ghi regions vào chính dict lease của sổ")
    check("REF_NS" not in blk.split("guard_regions(")[1][:400],
          "không dựng ref/namespace riêng cho vùng")


def test_renew_and_status_carry_regions():
    """`renew` là heartbeat — heartbeat mà làm mất quyền thì tệ hơn không có."""
    print("renew giữ nguyên regions · status in cột regions (#2270)")

    led = {"issue": 4243, "state": "executing", "epoch": 2,
           "lease": {"session": "MINE1234", "epoch": 2, "ttl": 2700,
                     "regions": ["backend/app/Services"]}}
    written: list[dict] = []
    tal.lease_file = lambda start=None, search=True: (
        Path("/tmp/x"), {"issue": 4243, "session": "MINE1234", "epoch": 2})
    tal.ledger_read = lambda n: (led, 7, tal.now())
    tal.ledger_write = lambda d, cid, note=None: written.append(d) or cid

    tal.cmd_renew(types.SimpleNamespace(json=False))
    check(len(written) == 1 and written[0]["lease"]["regions"] == ["backend/app/Services"],
          "renew ghi lại sổ mà regions còn nguyên",
          json.dumps(written[-1]["lease"] if written else {}, ensure_ascii=False))

    # status: cột regions lấy từ lease, và KHÔNG lấy cho key `pr-` (lease review
    # đọc sổ của issue → cùng một vùng sẽ hiện hai dòng như hai người giữ).
    tal.refs_all_full = lambda: [{"key": "issue-4243", "sha": "aa", "type": "commit"},
                                 {"key": "pr-77", "sha": "bb", "type": "commit"}]
    tal.pr_issue = lambda n: 4243
    tal.lease_expired = lambda d, upd: False
    tal.open_issues = lambda: []
    tal.gh_json = lambda args, default=None: [] if default is None else default
    tal.live_lease_issues = lambda: set()
    board = tal.cmd_status(types.SimpleNamespace(json=True))
    rows = {r["key"]: r for r in board["leases"]}
    check(rows["issue-4243"]["regions"] == ["backend/app/Services"],
          "status: dòng issue in vùng đang giữ", str(rows["issue-4243"]))
    check(rows["pr-77"]["regions"] == [],
          "status: dòng lease review KHÔNG nhân bản vùng của issue",
          str(rows["pr-77"]))


# ─────────────────────────────────────────────────────────────────────────────
# #2153 — verdict sau merge là vô nghĩa; lô merge không được đè lên review sống
# ─────────────────────────────────────────────────────────────────────────────

def test_verdict_refuses_a_merged_pr():
    """Race đo được ngoài đời: review claim 14:17:49Z, PR merge 14:18:38Z, verdict
    `changes` (blocking, ĐÚNG) ghi 14:20:11Z — muộn 93 giây. Hệ quả: issue #2110
    dính `agent:changes-requested` + `status:blocked` trên một PR đã đóng, kẹt
    vĩnh viễn, còn điểm blocking thật nằm nguyên trên dev không ai nợ."""
    print("cmd_review_verdict (#2153: PR đã merge ⇒ TỪ CHỐI ghi verdict)")

    calls: list[list[str]] = []
    written: list[str] = []
    labels: list = []
    refs_deleted: list[str] = []
    unlocks: list[str] = []

    def fake_gh(args, check=True, stdin=None):
        calls.append(args)

        class R:
            stdout = ""
            returncode = 0
        return R()

    tal.gh = fake_gh
    tal.pr_issue = lambda pr: 2110
    tal.ledger_read = lambda issue: ({"issue": 2110, "group": [2110], "state": "reviewing",
                                      "review_rounds": 0, "history": []}, 1, tal.now())
    tal.ledger_write = lambda led, cid, note=None: written.append(note)
    tal.set_state_labels = lambda *a, **k: labels.append(a)
    tal.ref_delete = lambda key: refs_deleted.append(key)
    tal.local_unlock = lambda key: unlocks.append(key)
    tal.session_id = lambda: "revw0000aaaa"
    tal.gh_json = lambda args, default=None: (
        {"state": "MERGED", "mergedAt": "2026-08-07T14:18:38Z", "headRefOid": "feedface"}
        if "state,mergedAt,headRefOid" in args else default)

    class A:
        pr = 2148
        verdict = "changes"
        body = "issue (blocking): con trỏ submodule tụt 1"
        body_file = None
        allow_self = False

    try:
        tal.cmd_review_verdict(A())
        failed = None
    except tal.Fail as e:
        failed = str(e)

    check(failed is not None, "PR MERGED → Fail, không exit 0")
    check(bool(failed) and "2153" in failed and "issue MỚI" in failed,
          "thông điệp nói rõ: verdict vô nghĩa, mở issue mới cho điểm blocking", str(failed)[:200])
    check(not any(c[:2] == ["pr", "comment"] for c in calls), "KHÔNG ghi comment verdict lên PR")
    check(written == [], "KHÔNG ghi sổ — giữ nguyên trạng thái mà merge đã ghi", str(written))
    check(labels == [], "KHÔNG dán nhãn changes-requested lên issue đã khép", str(labels))
    check(refs_deleted == ["pr-2148"] and unlocks == ["pr-2148"],
          "lease review được NHẢ, không treo `?` tới hết TTL", f"{refs_deleted} {unlocks}")

    # Không ĐO được state ⇒ cũng từ chối kết luận (#2151), nhưng GIỮ lease để thử lại.
    refs_deleted.clear()
    unlocks.clear()
    calls.clear()
    tal.gh_json = lambda args, default=None: default
    try:
        tal.cmd_review_verdict(A())
        failed = None
    except tal.Fail as e:
        failed = str(e)
    check(failed is not None and "KHÔNG ĐO ĐƯỢC" in str(failed),
          "state không đọc được → không kết luận (#2151)", str(failed)[:160])
    check(refs_deleted == [] and unlocks == [], "không đo được thì KHÔNG nhả lease",
          f"{refs_deleted} {unlocks}")


def test_merge_blockers_stops_a_pr_under_live_review_lease():
    """#2153 + #2874 — PR đang có review lease SỐNG thì KHÔNG merge được, ở MỌI đường.

    Bài này đo `merge_blockers` chứ không đo `cmd_merge_batch`, và chỗ đo là điều
    quan trọng nhất của #2874.

    Bản trước ghim hành vi ở `merge-batch` **và stub `merge_blockers`** — nên nó
    chứng minh được đúng một đường, còn ba đường kia (`merge-queue`, `cmd_merge`,
    và đường 4179) đi qua cổng chung mà không ai canh. Ngày 2026-08-14/15 ba
    session độc lập cùng merge đè lên review đang chạy (#2833, #2867, #2862), cả
    ba qua `gh pr merge` — và lý do có tính hệ thống: mọi session đẩy PR bằng
    CÙNG một tài khoản GitHub nên không ai duyệt được PR của chính mình, ai thẩm
    tra xong cũng rơi về đường không rào.

    Đo ở cổng chung thì một bài phủ cả bốn đường.
    """
    print("merge_blockers (#2874: lease review SỐNG chặn ở CỔNG CHUNG, không riêng lô)")

    tal.gh_json = lambda args, default=None: (
        {"state": "OPEN", "isDraft": False, "mergeable": "MERGEABLE",
         "headRefName": "issue-10", "headRefOid": "aaa", "labels": []}
        if args[:2] == ["pr", "view"] else default
    )
    tal.pr_issue = lambda pr: 10
    tal.issue_data = lambda n: {"labels": [tal.L_PASSED]}
    tal.pr_verdict_pass_evidence = lambda pr, sha, head_branch=None: ("aaa", "cid")
    tal.pr_checks = lambda pr: ("pass", [])
    tal.ledger_read = lambda n: ({"issue": n, "state": "reviewing"}, 1, tal.now())

    expired = {"v": False}
    tal.lease_expired = lambda led, upd: expired["v"]

    # (a) có lease sống cho ĐÚNG PR này → chặn
    tal.refs_all = lambda: ["pr-101"]
    _, why, _ = tal.merge_blockers(101, require_ci=False)
    check(any("lease pr-101" in w for w in why),
          "lease review SỐNG → merge_blockers chặn", str(why))

    # (b) lease quá TTL → KHÔNG chặn. Một lease mồ côi giữ con tin mọi lượt merge
    #     là rào tệ hơn không rào; `tal gc` mới là thứ dọn nó.
    expired["v"] = True
    _, why, _ = tal.merge_blockers(101, require_ci=False)
    check(why == [], "lease QUÁ TTL không chặn", str(why))

    # (c) lease của PR KHÁC → không chặn oan. Thiếu vế này thì một rào chặn tất
    #     cũng qua được bài (a), và rào chặn oan là rào sắp bị tắt.
    expired["v"] = False
    tal.refs_all = lambda: ["pr-999"]
    _, why, _ = tal.merge_blockers(101, require_ci=False)
    check(why == [], "lease của PR khác → KHÔNG chặn oan", str(why))

    # (d) không có ref nào → không chặn
    tal.refs_all = lambda: []
    _, why, _ = tal.merge_blockers(101, require_ci=False)
    check(why == [], "không có lease nào → không chặn", str(why))


def test_merge_batch_inherits_the_review_lease_gate():
    """Lô vẫn phải né PR đang review — nhưng nay THỪA HƯỞNG từ cổng chung.

    Giữ bài này sau khi phép kiểm dời chỗ, vì thứ cần bảo đảm là **kết quả** (PR
    đang review không vào lô), không phải dòng code nào tạo ra nó. Bỏ nó đi là để
    một lần refactor tương lai lặng lẽ mở lại đúng lỗ #2153.
    """
    print("cmd_merge_batch (#2874: thừa hưởng rào lease từ merge_blockers)")

    def fake_gh_json(args, default=None):
        if args[:2] == ["pr", "list"]:
            return [
                {"number": 101, "title": "a", "headRefName": "issue-10",
                 "isDraft": False, "headRefOid": "aaa"},
                {"number": 102, "title": "b", "headRefName": "issue-11",
                 "isDraft": False, "headRefOid": "bbb"},
            ]
        return default

    tal.gh_json = fake_gh_json
    # Cổng chung THẬT, chỉ giả phần nó hỏi ra ngoài — đó là điểm của bài này.
    tal.pr_issue = lambda pr: 10 if pr == 101 else 11
    tal.issue_data = lambda n: {"labels": [tal.L_PASSED]}
    tal.pr_verdict_pass_evidence = lambda pr, sha, head_branch=None: ("x", "cid")
    tal.pr_checks = lambda pr: ("pass", [])
    tal.ledger_read = lambda n: ({"issue": n, "state": "reviewing"}, 1, tal.now())
    tal.refs_all = lambda: ["pr-101"]
    expired = {"v": False}
    tal.lease_expired = lambda led, upd: expired["v"]

    _view = {"state": "OPEN", "isDraft": False, "mergeable": "MERGEABLE",
             "headRefName": "issue-10", "headRefOid": "aaa", "labels": []}
    _outer = tal.gh_json

    def gh(args, default=None):
        if args[:2] == ["pr", "view"]:
            return dict(_view)
        return _outer(args, default)

    tal.gh_json = gh

    class A:
        limit = 0
        dry_run = True
        skip_suite = True
        suite = False
        json = False

    out = tal.cmd_merge_batch(A())
    prs = [x["pr"] for x in out["lot"]]
    check(prs == [102], "PR đang được review bị BỎ khỏi lô; PR còn lại vẫn đi", str(prs))

    expired["v"] = True
    out = tal.cmd_merge_batch(A())
    prs = [x["pr"] for x in out["lot"]]
    check(prs == [101, 102], "lease review QUÁ TTL không chặn lô", str(prs))


# ─────────────────────────────────────────────────────────────────────────────
# #2172 — rỗng-vì-bị-giữ ≠ rỗng-vì-hết-việc; chủ lease review nằm trong ref
# ─────────────────────────────────────────────────────────────────────────────

def test_ref_create_wraps_payload_in_a_tag_object():
    print("ref_create (#2172: payload → tag object; tạo tag hỏng thì rơi về sha trần)")

    gh_calls: list[list[str]] = []

    def fake_gh(args, check=True, stdin=None):
        gh_calls.append(args)

        class R:
            stdout = ""
            stderr = ""
            returncode = 0
        return R()

    tal.gh = fake_gh
    tal.gh_json = lambda args, default=None: (
        {"sha": "TAG0BEEF"} if any("git/tags" in str(x) for x in args) else default)

    ok = tal.ref_create("pr-9", "c0mm1t5ha", {"session": "s1"})
    ref_call = next(c for c in gh_calls if any("git/refs" in str(x) for x in c))
    check(ok is True and any("sha=TAG0BEEF" in str(x) for x in ref_call),
          "ref trỏ vào TAG object mang payload, không phải commit trần", str(ref_call))

    # Tạo tag hỏng → payload là phụ trợ, CAS vẫn phải chạy trên sha trần.
    gh_calls.clear()
    tal.gh_json = lambda args, default=None: default
    ok = tal.ref_create("pr-9", "c0mm1t5ha", {"session": "s1"})
    ref_call = next(c for c in gh_calls if any("git/refs" in str(x) for x in c))
    check(ok is True and any("sha=c0mm1t5ha" in str(x) for x in ref_call),
          "tag hỏng → rơi về sha trần, không chặn việc giành lease", str(ref_call))

    # Không payload → không đụng tới git/tags (đường cũ giữ nguyên giá).
    gh_calls.clear()
    tal.gh_json = lambda args, default=None: check_fail_if_called(args)

    def check_fail_if_called(args):
        raise AssertionError("không được gọi gh_json khi không có payload")

    ok = tal.ref_create("issue-7", "c0mm1t5ha")
    check(ok is True, "ref không payload vẫn tạo như cũ, không thêm round-trip")


def test_review_claim_stamps_owner_payload_on_the_ref():
    print("cmd_review_claim (#2172: ref mang danh tính chủ lease; #2153: PR đóng thì từ chối)")

    got: dict = {}
    tal.pr_issue = lambda pr: 55
    tal.ledger_read = lambda n: ({"issue": 55, "history": []}, 1, tal.now())
    tal.ledger_write = lambda led, cid, note=None: 1
    tal.local_lock = lambda k: True
    tal.local_unlock = lambda k: None
    tal.gh_json = lambda args, default=None: {"state": "OPEN"}
    tal.gh = lambda args, check=True, stdin=None: types.SimpleNamespace(stdout="", returncode=0)
    tal.head_sha = lambda: "beef"
    tal.session_id = lambda: "sess5555ffff"

    def cap(key, sha, payload=None):
        got["key"], got["payload"] = key, payload
        return True

    tal.ref_create = cap
    tal.cmd_review_claim(types.SimpleNamespace(pr=555, json=False, allow_self=False))
    p = got.get("payload") or {}
    check(got.get("key") == "pr-555" and p.get("session") == "sess5555ffff",
          "payload mang session THẬT của người claim", str(p)[:160])
    check(bool(p.get("expires_at")) and bool(p.get("host")) and bool(p.get("pid")),
          "payload đủ host/pid/hạn — status in được 'ai giữ, còn bao lâu'", str(p)[:200])

    # PR đã merge/đóng → chặn từ cửa vào (#2153), khỏi phí một lượt review.
    tal.gh_json = lambda args, default=None: {"state": "MERGED"}
    try:
        tal.cmd_review_claim(types.SimpleNamespace(pr=555, json=False, allow_self=False))
        refused = False
    except tal.Fail as e:
        refused = "2153" in str(e)
    check(refused, "PR đã MERGED → review-claim từ chối ngay (#2153)")


def test_review_queue_names_who_holds_a_claimed_pr():
    """Trước #2172: `eligible: []` cho CẢ "hết việc thật" lẫn "ba PR đang bị session
    khác giữ" — một session làm đúng luật sẽ báo hết việc trong khi việc vẫn còn."""
    print("cmd_review_queue (#2172: rỗng-vì-bị-giữ ≠ rỗng-vì-hết-việc, nêu AI giữ)")
    import contextlib
    import io

    tal.gh_json_required = lambda args: [
        {"number": 2166, "title": "t1", "headRefName": "issue-2130", "isDraft": False,
         "labels": [], "updatedAt": "2026-08-08T10:00:00Z", "author": {}, "headRefOid": "aaa"},
    ]
    tal.issue_data = lambda n: {"labels": [tal.L_AWAIT]}
    tal.refs_all_full = lambda: [{"key": "pr-2166", "sha": "tagsha", "type": "tag"}]
    tal.ref_payload = lambda sha: {"session": "326472eb-9999", "host": "mac-mini",
                                   "expires_at": tal.iso(tal.now() + tal.timedelta(seconds=600))}
    tal.session_id = lambda: "deadbeefcafe"

    res = tal.cmd_review_queue(types.SimpleNamespace(json=True))
    check(res["eligible"] == [], "không có PR nhận được")
    check(len(res["claimed"]) == 1 and res["claimed"][0]["by"] == "326472eb",
          "claimed nêu ĐÚNG session đang giữ", str(res["claimed"]))
    check(res["claimed"][0]["expires_in"] in ("9m", "10m") and res["claimed"][0]["orphan"] is False,
          "kèm còn bao lâu, và không bị gọi nhầm là mồ côi", str(res["claimed"]))

    buf = io.StringIO()
    with contextlib.redirect_stdout(buf):
        tal.cmd_review_queue(types.SimpleNamespace(json=False))
    text = buf.getvalue()
    check("KHÔNG phải hết việc" in text, "bản chữ nói rõ: đang bị giữ, không im lặng", text)
    check("326472eb" in text, "bản chữ nêu danh tính người giữ", text)

    # Ref KHÔNG mang payload → không đọc được chủ: phải nói khác lease sống.
    tal.refs_all_full = lambda: [{"key": "pr-2166", "sha": "c0ffee", "type": "commit"}]
    res = tal.cmd_review_queue(types.SimpleNamespace(json=True))
    check(res["claimed"][0]["orphan"] is True and res["claimed"][0]["by"] == "?",
          "ref kiểu cũ/mồ côi → by='?', orphan=True", str(res["claimed"]))

    # Hết việc THẬT: đo được, cả hai danh sách rỗng, và bản chữ nói đúng như vậy.
    tal.gh_json_required = lambda args: []
    buf = io.StringIO()
    with contextlib.redirect_stdout(buf):
        res = tal.cmd_review_queue(types.SimpleNamespace(json=False))
    check(res["eligible"] == [] and res["claimed"] == [], "backlog rỗng thật: hai danh sách rỗng")
    check("rỗng THẬT" in buf.getvalue(), "bản chữ phân biệt được với 'đang bị giữ'", buf.getvalue())


def test_status_reads_review_lease_owner_from_ref_payload():
    """`session ?` từng in cho CẢ lease sống lẫn mồ côi — loại mơ hồ đẩy người ta tới
    chỗ xoá ref bằng tay, và xoá nhầm lease sống là hai session cùng review một PR."""
    print("tal status (#2172: chủ lease review từ PAYLOAD của ref; '?' = thật sự không đọc được)")
    import contextlib
    import io

    payloads = {"tagsha": {"session": "326472eb-live", "host": "mac-mini",
                           "expires_at": tal.iso(tal.now() + tal.timedelta(seconds=1200))}}
    tal.refs_all_full = lambda: [{"key": "pr-2168", "sha": "tagsha", "type": "tag"},
                                 {"key": "pr-2166", "sha": "baresha", "type": "commit"}]
    tal.ref_payload = lambda sha: payloads.get(sha)
    tal.pr_issue = lambda n: {2168: 2133, 2166: 2130}[n]
    # Sổ của issue mang lease CODE của session KHÁC — status không được lấy nhầm nó.
    tal.ledger_read = lambda n: ({"issue": n, "state": "reviewing",
                                  "lease": {"session": "CODER9999", "ttl": 2700}}, 1, tal.now())
    tal.lease_expired = lambda d, u: False
    tal.open_issues = lambda: []
    tal.gh_json = lambda args, default=None: [] if default is None else default
    tal.live_lease_issues = lambda: set()

    board = tal.cmd_status(types.SimpleNamespace(json=True))
    rows = {r["key"]: r for r in board["leases"]}
    check(rows["pr-2168"]["session"] == "326472eb",
          "lease sống → in danh tính THẬT từ payload, không phải '?'", str(rows["pr-2168"]))
    check(rows["pr-2168"]["session"] != "CODER999" and rows["pr-2168"]["host"] == "mac-mini",
          "và KHÔNG lấy nhầm lease CODE trong sổ của issue", str(rows["pr-2168"]))
    check(rows["pr-2168"]["expired"] is False and rows["pr-2168"]["expires_in"] in ("19m", "20m"),
          "hạn tính từ payload của chính lease review", str(rows["pr-2168"]))
    check(rows["pr-2166"]["session"] == "?" and rows["pr-2166"].get("no_payload") is True,
          "ref không payload → '?' + cờ no_payload — '?' CHỈ còn nghĩa này", str(rows["pr-2166"]))
    check(rows["pr-2166"]["orphan_ref"] is False,
          "vẫn KHÔNG xui `tal unlock` lên lease review (#1406 giữ nguyên)")

    buf = io.StringIO()
    with contextlib.redirect_stdout(buf):
        tal.cmd_status(types.SimpleNamespace(json=False))
    text = buf.getvalue()
    check("KHÔNG ĐỌC ĐƯỢC CHỦ" in text and "ĐỪNG unlock tay" in text,
          "bản chữ chỉ đường về `tal gc`, không về `unlock`", text)



def test_merge_requires_github_verdict():
    """#2261 — merge phải thấy verdict=pass trên GitHub khớp HEAD, không chỉ nhãn."""
    print("merge_blockers: đòi comment verdict=pass khớp sha (#2261)")
    src = (HERE / "tal").read_text()
    blk = src[src.index("def merge_blockers("):]
    blk = blk[:blk.index("\ndef ", 10)]
    check("pr_verdict_pass_evidence(" in blk, "merge_blockers gọi đọc verdict từ GitHub")
    check("verdict=pass" in blk, "thông báo chặn nói rõ thiếu verdict pass")

    tal.issue_data = lambda n: {"labels": [tal.L_PASSED]}
    tal.pr_issue = lambda pr: 2261
    tal.pr_checks = lambda pr: ("pass", [])
    tal.pr_verdict_pass_evidence = lambda pr, sha, head_branch=None: (None, None)
    tal.gh_json = lambda args, **k: {
        "state": "OPEN", "isDraft": False, "mergeable": "MERGEABLE",
        "headRefName": "issue-2261", "headRefOid": "abc123def4567890", "labels": [],
    }
    _, why, ev = tal.merge_blockers(99, require_ci=False)
    check(any("verdict=pass" in w for w in why), "thiếu verdict trên GitHub → chặn", str(why))
    check(ev == (None, None), "không có verdict → bằng chứng rỗng", str(ev))

    tal.pr_verdict_pass_evidence = lambda pr, sha, head_branch=None: ("abc123def456", "cmt-1")
    _, why2, ev2 = tal.merge_blockers(99, require_ci=False)
    check(not any("verdict=pass" in w for w in why2), "có verdict khớp sha → không chặn vì verdict", str(why2))
    check(ev2 == ("abc123def456", "cmt-1"), "bằng chứng verdict trả về cho người gọi dùng lại", str(ev2))


def test_pr_verdict_evidence_sha_matching():
    """#2261 vòng 2 — phép khớp SHA (thứ duy nhất phân biệt "đã review bản này"
    với "bản cũ") phải được ghim bằng hàm THẬT, không stub: gỡ `sha={prefix}`
    khỏi regex thì các ca dưới phải đỏ."""
    print("pr_verdict_pass_evidence: khớp sha bằng hàm thật (#2261 vòng 2)")
    head = "abc123def4567890aaaabbbbccccddddeeeeffff"
    prefix = head[:12]
    box = {"comments": []}

    def fake_gh_json(args, default=None):
        if "comments" in args:
            return {"comments": box["comments"]}
        return {"headRefOid": head}

    tal.gh_json = fake_gh_json

    box["comments"] = [{"id": "c1", "body":
        f"<!-- tal:review verdict=pass round=2 changes=0 sha={prefix} reviewer=x -->\nOK"}]
    vsha, vcid = tal.pr_verdict_pass_evidence(99, head)
    check(vsha == prefix and vcid == "c1",
          "verdict=pass đúng sha HEAD → trả (prefix, comment_id)", f"{vsha},{vcid}")

    box["comments"] = [{"id": "c2", "body":
        "<!-- tal:review verdict=pass round=1 sha=000011112222 reviewer=x -->\nOK bản CŨ"}]
    vsha, vcid = tal.pr_verdict_pass_evidence(99, head)
    check(vsha is None and vcid is None,
          "verdict=pass nhưng sha CŨ (bản chưa ai review lại) → KHÔNG tính", f"{vsha},{vcid}")

    box["comments"] = [{"id": "c3", "body":
        f"<!-- tal:review verdict=changes round=1 changes=1 sha={prefix} reviewer=x -->\nSỬA"}]
    vsha, vcid = tal.pr_verdict_pass_evidence(99, head)
    check(vsha is None and vcid is None,
          "verdict=changes đúng sha → KHÔNG phải bằng chứng pass", f"{vsha},{vcid}")


def test_merge_ledger_reuses_gate_verdict():
    """#2261 vòng 2 — sổ merge dùng lại bằng chứng từ CỔNG, không hỏi lại GitHub.

    Lỗi thật: PR có submodule → `realign_pointers` push commit căn pointer →
    HEAD đổi → lần tra thứ hai không thấy verdict → sổ ghi "verdict=KHÔNG_CÓ"
    cho một merge đã qua cổng. Sentinel dưới nổ nếu cmd_merge còn gọi lại
    `pr_verdict_pass_evidence` sau cổng."""
    print("cmd_merge: sổ ghi verdict từ cổng, không tra lại (#2261 vòng 2)")

    def boom(*a, **k):
        raise AssertionError("cmd_merge KHÔNG được tra verdict lần hai sau cổng")

    written = {}
    tal.merge_blockers = lambda pr, require_ci=False: (10, [], ("feedbeef1234", "c9"))
    tal.pr_verdict_pass_evidence = boom
    tal.pr_dangling_pointers = lambda pr: []
    tal.remove_worktree = lambda i: None
    tal.gh = _gh_stub_with_head()
    tal.run = _run_stub_ancestor(True)      # #2988 — merge thật ⇒ head đã vào base
    tal.ledger_read = lambda i: ({"state": "reviewing", "group": [10]}, "cid-1", None)
    tal.ledger_write = lambda led, cid, msg: written.setdefault("msg", msg)
    tal.set_state_labels = lambda *a, **k: None
    tal.closable = lambda n: (False, "")

    class A:
        pr = 99
        force = False
        batch_verified = True
        no_subs = True
        self_merge = False
        note = ""

    tal.cmd_merge(A())
    check("verdict=pass sha=feedbeef1234 comment=c9" in written.get("msg", ""),
          "sổ mang đúng sha ĐÃ REVIEW từ cổng", written.get("msg", "(không ghi)"))


def test_unlock_pr_requires_note():
    """#2261 — mở khoá lease review phải có --note."""
    print("cmd_unlock: pr-* bắt buộc --note (#2261)")
    src = (HERE / "tal").read_text()
    blk = src[src.index("def cmd_unlock("):]
    blk = blk[:blk.index("\ndef ", 10)]
    check('key.startswith("pr-")' in blk and "--note" in blk,
          "cmd_unlock kiểm pr-* và đòi note")
    check("MỞ KHOÁ LEASE REVIEW TAY" in blk, "ghi comment lên PR khi unlock pr-*")

    tal.ref_delete = lambda k: None
    tal.local_unlock = lambda k: None
    tal.gh = _gh_stub_with_head()
    tal.run = _run_stub_ancestor(True)      # #2988 — merge thật ⇒ head đã vào base

    class A:
        key = "pr-501"
        force = True
        note = ""

    try:
        tal.cmd_unlock(A())
        check(False, "unlock pr-* không note phải FAIL")
    except tal.Fail as e:
        check("2261" in str(e) or "note" in str(e).lower(), "nói rõ thiếu note", str(e))

def test_verdict_resets_issue_when_pr_closed_without_merge():
    """#2289 — CLOSED không merge ≠ MERGED: issue phải về hàng đợi code."""
    print("cmd_review_verdict (#2289: CLOSED không merge → trả issue về queue)")

    written: list[str] = []
    labels: list = []

    tal.pr_issue = lambda pr: 2289
    tal.ledger_read = lambda issue: ({"issue": 2289, "group": [2289], "state": "reviewing",
                                      "review_rounds": 0, "history": []}, 1, tal.now())
    tal.ledger_write = lambda led, cid, note=None: written.append(note)
    tal.set_state_labels = lambda issue, desired, drop=None: labels.append((issue, desired, drop))
    tal.ref_delete = lambda key: None
    tal.local_unlock = lambda key: None
    tal.session_id = lambda: "revw0000bbbb"
    tal.issue_data = lambda n: {"number": n, "state": "OPEN", "labels": []}
    tal.gh = lambda *a, **k: type("R", (), {"stdout": "", "stderr": "", "returncode": 0})()
    tal.gh_json = lambda args, default=None: (
        {"state": "CLOSED", "mergedAt": None, "headRefOid": "abc"}
        if "state,mergedAt,headRefOid" in args else default)

    class A:
        pr = 9001
        verdict = "pass"
        body = "ok"
        body_file = None
        allow_self = False

    try:
        tal.cmd_review_verdict(A())
        failed = None
    except tal.Fail as e:
        failed = str(e)

    check(failed is not None and "2289" in failed and "tal claim 2289" in failed,
          "nói rõ issue đã trả về hàng đợi", str(failed)[:200])
    check(any("đóng không merge" in w for w in written), "ghi sổ lý do", str(written))
    check(labels and tal.L_READY in labels[0][1] and tal.L_AWAIT in (labels[0][2] or set()),
          "gắn lại ready, gỡ awaiting-review", str(labels))



def test_verdict_skips_reset_when_issue_shipped():
    """#2289 — không gỡ shipped khi PR cũ closed-không-merge."""
    print("cmd_review_verdict (#2289: shipped issue → không reset)")

    written: list[str] = []
    labels: list = []

    tal.pr_issue = lambda pr: 2289
    tal.ledger_read = lambda issue: ({"issue": 2289, "group": [2289], "state": "shipped",
                                      "review_rounds": 0, "history": []}, 1, tal.now())
    tal.ledger_write = lambda led, cid, note=None: written.append(note)
    tal.set_state_labels = lambda issue, desired, drop=None: labels.append((issue, desired, drop))
    tal.issue_data = lambda n: {"number": n, "state": "OPEN", "labels": [tal.L_SHIPPED]}
    tal.ref_delete = lambda key: None
    tal.local_unlock = lambda key: None
    tal.session_id = lambda: "revw0000cccc"
    tal.gh = lambda *a, **k: type("R", (), {"stdout": "", "stderr": "", "returncode": 0})()
    tal.gh_json = lambda args, default=None: (
        {"state": "CLOSED", "mergedAt": None, "headRefOid": "abc"}
        if "state,mergedAt,headRefOid" in args else default)

    class A:
        pr = 9002
        verdict = "pass"
        body = "ok"
        body_file = None
        allow_self = False

    try:
        tal.cmd_review_verdict(A())
        failed = None
    except tal.Fail as e:
        failed = str(e)

    check(failed is not None, "vẫn từ chối verdict trên PR đóng", str(failed)[:200])
    check(labels == [], "không gắn lại ready", str(labels))
    check(any("bỏ qua reset" in w for w in written), "ghi sổ bỏ qua", str(written))
    check(all("queued" not in w or "bỏ qua" in w for w in written), "không ghi queued", str(written))



# ─────────────────────────────────────────────────────────────────────────────
# #2300 — hardening tổng lực: ghim TỪNG đường lỗi audit tìm ra
# ─────────────────────────────────────────────────────────────────────────────


def test_2300_env_int_rejects_garbage():
    print("_env_int: env rác → thoát 2 có thông điệp, không traceback (#2300 A17)")
    os.environ["TAL_X_TEST"] = "45m"
    try:
        try:
            tal._env_int("TAL_X_TEST", 1)
            check(False, "TAL_X_TEST=45m phải bị từ chối")
        except SystemExit as e:
            check(e.code == 2, "thoát mã 2", str(e.code))
    finally:
        del os.environ["TAL_X_TEST"]
    check(tal._env_int("TAL_KHONG_TON_TAI_2300", 7) == 7, "thiếu env → default")


def test_2300_json_pages_concat():
    print("_json_pages: --paginate nhiều trang (mảng nối nhau) vẫn đọc trọn (#2300)")
    check(tal._json_pages('[1,2]\n[3]') == [1, 2, 3], "hai trang → một list")
    check(tal._json_pages('[{"a":1}]') == [{"a": 1}], "một trang như cũ")


def test_2300_ref_exists_three_states():
    print("ref_exists: True / False-404-thật / RAISE khi không đo được (#2300 A13)")

    def gh_404(args, check=True, stdin=None):
        return type("R", (), {"returncode": 1, "stdout": "", "stderr": "gh: Not Found (HTTP 404)"})()

    def gh_flake(args, check=True, stdin=None):
        return type("R", (), {"returncode": 1, "stdout": "", "stderr": "API rate limit exceeded"})()

    tal.gh = gh_404
    check(tal.ref_exists("issue-9") is False, "404 thật → False")
    tal.gh = gh_flake
    try:
        tal.ref_exists("issue-9")
        check(False, "quota cạn phải RAISE, không phải False")
    except tal.Fail as e:
        check("KHÔNG ĐO ĐƯỢC" in str(e), "nói rõ là không đo được", str(e)[:80])


def test_2300_ref_delete_reports():
    print("ref_delete: 404 = đã mất (True); lỗi khác = False + warn (#2300 A10)")
    warns = []
    tal.warn = lambda m: warns.append(m)
    tal.gh = lambda args, check=True, stdin=None: type(
        "R", (), {"returncode": 1, "stdout": "", "stderr": "Reference does not exist"})()
    check(tal.ref_delete("issue-9") is True, "ref vốn không tồn tại → True (đã mất)")
    tal.gh = lambda args, check=True, stdin=None: type(
        "R", (), {"returncode": 1, "stdout": "", "stderr": "API rate limit exceeded"})()
    check(tal.ref_delete("issue-9") is False, "xoá fail thật → False")
    check(any("KHÔNG xoá được" in w for w in warns), "và warn nói to", str(warns))


def test_2300_pr_issue_gone_vs_fail_vs_fallback():
    print("pr_issue: Gone(404) ≠ Fail(không đo được); fallback chỉ dòng Closes đứng riêng (#2300 A4/F7)")
    tal.gh = lambda args, check=True, stdin=None: type(
        "R", (), {"returncode": 1, "stdout": "", "stderr": "Could not resolve to a PullRequest"})()
    try:
        tal.pr_issue(9)
        check(False, "phải Gone")
    except tal.Gone:
        check(True, "PR 404 thật → Gone")
    except tal.Fail:
        check(False, "PR 404 thật ra Fail thường là reap sẽ giữ ref rác mãi")

    tal.gh = lambda args, check=True, stdin=None: type(
        "R", (), {"returncode": 1, "stdout": "", "stderr": "API rate limit exceeded"})()
    try:
        tal.pr_issue(9)
        check(False, "phải Fail")
    except tal.Gone:
        check(False, "quota cạn mà thành Gone là reap XOÁ lease review sống")
    except tal.Fail:
        check(True, "không đo được → Fail (giữ nguyên hiện trạng)")

    def gh_body(body):
        payload = json.dumps({"headRefName": "feat/tay", "body": body, "number": 9})
        return lambda args, check=True, stdin=None: type(
            "R", (), {"returncode": 0, "stdout": payload, "stderr": ""})()

    tal.gh = gh_body("như đã bàn ở chỗ fixes #123 trong đoạn trích")
    try:
        tal.pr_issue(9)
        check(False, "fixes #123 giữa văn xuôi KHÔNG được suy")
    except tal.Fail:
        check(True, "văn xuôi → Fail bắt chỉ định tường minh")
    tal.gh = gh_body("mô tả\n\nCloses #77\n")
    check(tal.pr_issue(9) == 77, "dòng Closes đứng riêng → suy đúng")


def test_2300_session_id_hashed_fallback():
    print("session_id: fallback shell được BĂM — [:8] vẫn phân biệt (#2300 D11)")
    # #2400 — lấy danh sách thẳng từ `tal`, đừng liệt kê tay. Bản liệt kê tay
    # viết trước #2369 sót `CODEX_THREAD_ID`, nên ca này ĐỎ với mọi phiên Codex:
    # `session_id()` không bao giờ rơi xuống nhánh băm khi biến đó còn đứng.
    _ident = (tal._SESSION_OVERRIDE_VAR, *tal._AGENT_SESSION_VARS)
    old_env = {k: os.environ.pop(k, None) for k in _ident}
    try:
        sid = tal.session_id()
        check(sid.startswith("shell") and len(sid) == 16, "shell + 11 hex", sid)
        check(sid[:8] != "shell-" + tal.socket.gethostname()[:2],
              "8 ký tự đầu không còn là tiền tố chung", sid[:8])
    finally:
        for k, v in old_env.items():
            if v is not None:
                os.environ[k] = v


def test_2300_set_state_labels_safe_and_preserve():
    print("set_state_labels: đọc hỏng → BỎ lần ghi; preserve giữ nhãn review (#2300 A8/D4)")
    calls = []

    def gh_fail(args, check=True, stdin=None):
        calls.append(args)
        return type("R", (), {"returncode": 1, "stdout": "", "stderr": "boom"})()

    warns = []
    tal.warn = lambda m: warns.append(m)
    tal.gh = gh_fail
    tal.set_state_labels(9, {tal.L_WORKING})
    check(not any("PUT" in " ".join(a) for a in calls), "GET fail → KHÔNG PUT", str(calls))
    check(any("BỎ lần ghi" in w for w in warns), "warn nói rõ vì sao bỏ")

    puts = []

    def gh_ok(args, check=True, stdin=None):
        if "-X" in args and "PUT" in args:
            puts.append(stdin)
            return type("R", (), {"returncode": 0, "stdout": "", "stderr": ""})()
        return type("R", (), {"returncode": 0,
                              "stdout": json.dumps([tal.L_PASSED, "bug", tal.L_AWAIT]),
                              "stderr": ""})()

    tal.gh = gh_ok
    tal.set_state_labels(9, {tal.L_WORKING}, preserve={tal.L_PASSED, tal.L_AWAIT})
    final = json.loads(puts[0])["labels"]
    check(tal.L_PASSED in final and tal.L_AWAIT in final and tal.L_WORKING in final and "bug" in final,
          "preserve giữ nhãn review qua lần ghi của claim", str(final))

    puts.clear()
    tal.set_state_labels(9, {tal.L_WORKING})
    final = json.loads(puts[0])["labels"]
    check(tal.L_PASSED not in final, "không preserve → nhãn MANAGED bị thay như cũ", str(final))


def test_2300_release_refuses_foreign_and_cwd_mismatch():
    print("release: không nhả lease người khác; --issue tường minh thắng CWD (#2300 A1/A3)")
    # A3 — lease sống của session khác
    tal.session_id = lambda: "me-1234567"
    tal.ledger_read = lambda issue: (
        {"issue": issue, "group": [issue], "state": "executing",
         "lease": {"session": "other-999", "keys": [f"issue-{issue}"], "ttl": 2700,
                   "expires_at": "x"}, "history": []}, 1, tal.now())
    deleted = []
    tal.ref_delete = lambda k: deleted.append(k)
    tal.ledger_write = lambda led, cid, note=None: check(False, "KHÔNG được ghi sổ khi từ chối")
    try:
        tal.release(50, "queued", set(), "note")
        check(False, "phải từ chối")
    except tal.Fail as e:
        check("other-99" in str(e) and deleted == [], "nêu đúng chủ + không xoá gì", str(e)[:120])

    # A1 — thẻ CWD thuộc issue KHÁC --issue
    tal.lease_file = lambda *a, **k: (Path("/tmp"), {"issue": 2050})
    released = []
    tal.release = lambda issue, *a, **k: released.append(issue)

    class A:
        issue = 2162
        state = "abandon"
        note = "x"

    try:
        tal.cmd_release(A())
        check(False, "lệch thẻ/tham số phải DỪNG")
    except tal.Fail as e:
        check("2162" in str(e) and "2050" in str(e) and released == [],
              "nói rõ hai con số, không release gì", str(e)[:160])


def test_2300_reap_spares_group_member_and_unmeasurable_pr():
    print("reap: chừa ref thành viên nhóm sống + KHÔNG xoá pr-N khi không đo được (#2300 A2/A4)")
    deleted = []
    tal.ref_delete = lambda k: deleted.append(k)
    tal.local_unlock = lambda k: None

    # A2 — issue-11 là thành viên nhóm #10 có lease sống
    tal.refs_all = lambda: ["issue-11"]
    tal.parent_of = lambda n, body=None: 10
    tal.ledger_read = lambda issue: (
        {"issue": issue, "group": [10, 11], "state": "executing",
         "lease": {"session": "s", "keys": ["issue-10", "issue-11"], "ttl": 2700},
         "history": []}, 1, tal.now())
    acts = tal.reap_leases(dry=False)
    check(any("thành viên nhóm #10" in x["action"] for x in acts),
          "nói rõ vì sao để nguyên", str(acts))
    check(deleted == [], "KHÔNG xoá ref con của nhóm sống", str(deleted))

    # A4 — pr-9 không đo được thì để nguyên; Gone thì xoá
    tal.refs_all = lambda: ["pr-9"]
    tal.pr_issue = lambda pr: (_ for _ in ()).throw(tal.Fail("KHÔNG ĐO ĐƯỢC PR"))
    acts = tal.reap_leases(dry=False)
    check(any("KHÔNG đo được" in x["action"] for x in acts) and deleted == [],
          "không đo được → giữ ref", str(acts))
    tal.pr_issue = lambda pr: (_ for _ in ()).throw(tal.Gone("PR không tồn tại"))
    acts = tal.reap_leases(dry=False)
    check("pr-9" in deleted and any("404 thật" in x["action"] for x in acts),
          "404 thật → xoá", str(acts))


def test_2300_requeue_contract():
    print("tal requeue: đường chính danh mở dead-letter (#2300 D3)")
    written = []
    labelled = []
    tal.issue_data = lambda n: {"number": n, "state": "open", "labels": [tal.L_DEAD, tal.L_BLOCKED]}
    tal.ledger_read = lambda n: ({"issue": n, "group": [n], "state": "dead_letter",
                                  "review_rounds": 3, "reaps": 2, "attempts": 9,
                                  "lease": None, "history": []}, 1, tal.now())
    tal.ledger_write = lambda led, cid, note=None: (written.append(json.loads(json.dumps(led))), 1)[1]
    tal.set_state_labels = lambda n, desired, drop=None, preserve=None: labelled.append((n, desired))
    tal.ref_exists = lambda k: False

    class A:
        issue = 77
        note = "root cause đã rõ"
        json = False

    out = tal.cmd_requeue(A())
    check(written and written[0]["state"] == "queued" and written[0]["review_rounds"] == 0
          and written[0]["reaps"] == 0 and written[0]["attempts"] == 9,
          "reset rounds/reaps, GIỮ attempts", str(written[0])[:160])
    check(labelled and tal.L_READY in labelled[0][1], "gắn lại agent:ready", str(labelled))

    class ANoNote:
        issue = 77
        note = ""
        json = False

    try:
        tal.cmd_requeue(ANoNote())
        check(False, "thiếu note phải Fail")
    except tal.Fail as e:
        check("--note" in str(e), "nói rõ thiếu note")

    tal.ref_exists = lambda k: True

    class A2:
        issue = 77
        note = "x"
        json = False

    try:
        tal.cmd_requeue(A2())
        check(False, "ref sống phải BUSY")
    except tal.Fail as e:
        check(e.code == tal.BUSY, "mã BUSY", str(e.code))


def test_2300_verdict_requires_lease_and_pinned_sha():
    print("review-verdict: đòi giữ lease + sha khớp bản đã claim (#2300 D1/D2)")
    tal.pr_issue = lambda pr: 60
    tal.session_id = lambda: "meme12345678"
    tal.ledger_read = lambda n: ({"issue": 60, "group": [60], "state": "reviewing",
                                  "review_rounds": 0, "history": []}, 1, tal.now())
    tal.gh_json = lambda args, default=None: (
        {"state": "OPEN", "mergedAt": None, "headRefOid": "bbbb222233334444"}
        if "state,mergedAt,headRefOid" in args else default)
    tal.gh = lambda *a, **k: type("R", (), {"returncode": 0, "stdout": "", "stderr": ""})()

    class A:
        pr = 60
        verdict = "pass"
        body = "ok"
        body_file = None
        allow_self = False

    # D1a — không có ref lease
    tal.refs_all_full = lambda: []
    try:
        tal.cmd_review_verdict(A())
        check(False, "không lease phải BUSY")
    except tal.Fail as e:
        check(e.code == tal.BUSY and "review-claim" in str(e), "chỉ đường claim", str(e)[:120])

    # D1b — lease của session khác (payload)
    tal.refs_all_full = lambda: [{"key": "pr-60", "sha": "t1", "type": "tag"}]
    tal.ref_payload = lambda sha: {"session": "khac-000000", "head_sha": "bbbb222233334444"}
    try:
        tal.cmd_review_verdict(A())
        check(False, "lease người khác phải BUSY")
    except tal.Fail as e:
        check(e.code == tal.BUSY and "khac-000" in str(e), "nêu đúng chủ", str(e)[:120])

    # D2 — sha ghim lúc claim ≠ head sống → nhả lease + Fail
    dels = []
    tal.ref_delete = lambda k: dels.append(k)
    tal.local_unlock = lambda k: None
    tal.ref_payload = lambda sha: {"session": "meme12345678", "head_sha": "aaaa111122223333"}
    try:
        tal.cmd_review_verdict(A())
        check(False, "sha lệch phải Fail")
    except tal.Fail as e:
        check("ĐỔI BẢN" in str(e) and "pr-60" in dels,
              "nói rõ đổi bản + đã nhả lease", f"{str(e)[:100]} dels={dels}")


def test_2300_verdict_pass_resets_rounds():
    print("review-verdict pass: review_rounds là CHUỖI thất bại — pass reset về 0 (#2300 D7)")
    written = []
    tal.pr_issue = lambda pr: 61
    tal.session_id = lambda: "rev-aaaa0000"
    tal.ledger_read = lambda n: ({"issue": 61, "group": [61], "state": "reviewing",
                                  "review_rounds": 2, "history": []}, 1, tal.now())
    tal.ledger_write = lambda led, cid, note=None: (written.append(json.loads(json.dumps(led))), 1)[1]
    tal.set_state_labels = lambda *a, **k: None
    tal.gh = lambda *a, **k: type("R", (), {"returncode": 0, "stdout": "", "stderr": ""})()
    tal.gh_json = lambda args, default=None: (
        {"state": "OPEN", "mergedAt": None, "headRefOid": "cccc"}
        if "state,mergedAt,headRefOid" in args else default)
    tal.refs_all_full = lambda: [{"key": "pr-61", "sha": "x", "type": "commit"}]
    tal.ref_delete = lambda k: None
    tal.local_unlock = lambda k: None

    class A:
        pr = 61
        verdict = "pass"
        body = "đạt"
        body_file = None
        allow_self = False

    tal.cmd_review_verdict(A())
    check(written and written[-1]["review_rounds"] == 0,
          "pass → rounds=0 (pha sau không thừa kế thất bại pha trước)", str(written[-1])[:120])


def test_2300_merge_order_crash_safe():
    print("cmd_merge: merge TRƯỚC — fail thì worktree/branch còn NGUYÊN (#2300 C1/C2)")
    removed = []
    tal.merge_blockers = lambda pr, require_ci=False: (10, [], ("evsha1234567", "c9"))
    tal.pr_dangling_pointers = lambda pr: []
    tal.remove_worktree = lambda i: removed.append(i)
    tal.ledger_read = lambda i: ({"issue": 10, "group": [10], "state": "review_passed",
                                  "history": []}, 1, tal.now())
    written = []
    tal.ledger_write = lambda led, cid, note=None: (written.append(note), 1)[1]
    tal.set_state_labels = lambda *a, **k: None
    tal.closable = lambda n, m=None: (False, "")
    tal.ref_exists = lambda k: False

    def gh_merge_fail(args, check=True, stdin=None):
        if args[:2] == ["pr", "merge"]:
            return type("R", (), {"returncode": 1, "stdout": "", "stderr": "not mergeable"})()
        # #2988 — head SHA đo được bình thường; thứ hỏng ở đây là chính lượt merge.
        out = '{"headRefOid":"feed0000face1111beef2222"}' if "headRefOid" in args else ""
        return type("R", (), {"returncode": 0, "stdout": out, "stderr": ""})()

    tal.gh = gh_merge_fail

    class A:
        pr = 99
        force = False
        no_subs = True
        self_merge = False
        note = None
        require_ci = False
        batch_verified = True
        json = False
        verdict_ev = None

    try:
        tal.cmd_merge(A())
        check(False, "merge fail phải Fail")
    except tal.Fail as e:
        check("GIỮ NGUYÊN" in str(e), "thông điệp nói trạng thái còn nguyên", str(e)[:120])
    check(removed == [], "worktree KHÔNG bị xoá khi merge fail", str(removed))

    calls = []

    def gh_ok(args, check=True, stdin=None):
        calls.append(args)
        # #2988 — `cmd_merge` đo head SHA bằng `gh_json_strict` TRƯỚC khi merge.
        out = '{"headRefOid":"feed0000face1111beef2222"}' if "headRefOid" in args else ""
        return type("R", (), {"returncode": 0, "stdout": out, "stderr": ""})()

    tal.gh = gh_ok
    tal.run = _run_stub_ancestor(True)          # merge thật ⇒ head đã vào base
    tal.cmd_merge(A())
    check(removed == [10], "merge OK rồi mới dọn worktree", str(removed))
    check(any("DELETE" in a and "refs/heads/issue-10" in " ".join(a) for a in calls),
          "xoá branch remote SAU merge (thay cho --delete-branch)", "")
    check(any("evsha1234567" in (w or "") for w in written),
          "sổ mang bằng chứng verdict từ cổng", str(written))


def _gh_stub_with_head(calls=None, head="feed0000face1111beef2222"):
    """Stub `gh` biết trả `headRefOid` — `cmd_merge` đo nó TRƯỚC khi merge (#2988)."""
    def fake(args, check=True, stdin=None):
        if calls is not None:
            calls.append(args)
        out = '{"headRefOid":"%s"}' % head if "headRefOid" in args else ""
        return type("R", (), {"returncode": 0, "stdout": out, "stderr": ""})()
    return fake


def _run_stub_ancestor(ok: bool, seen=None):
    """Stub `run` chỉ điều khiển `merge-base --is-ancestor` (#2988)."""
    def fake(cmd, cwd=None, check=True, stdin=None):
        if seen is not None:
            seen.append(cmd)
        rc = 0 if ("--is-ancestor" not in cmd or ok) else 1
        return type("R", (), {"returncode": rc, "stdout": "", "stderr": ""})()
    return fake


def test_2300_merge_batch_verified_reuses_evidence():
    print("cmd_merge(batch): blocker verdict-sha sau realign là dương tính giả — dùng evidence của lô (#2300 C2)")
    tal.merge_blockers = lambda pr, require_ci=False: (
        10, ["không có comment `tal:review verdict=pass` trên GitHub khớp head abc"], (None, None))
    tal.pr_dangling_pointers = lambda pr: []
    tal.remove_worktree = lambda i: None
    tal.ledger_read = lambda i: ({"issue": 10, "group": [10], "history": []}, 1, tal.now())
    written = []
    tal.ledger_write = lambda led, cid, note=None: (written.append(note), 1)[1]
    tal.set_state_labels = lambda *a, **k: None
    tal.closable = lambda n, m=None: (False, "")
    tal.gh = lambda args, *a, **k: type("R", (), {
        "returncode": 0,
        # #2988 — head SHA đo trước khi merge.
        "stdout": '{"headRefOid":"feed0000face1111beef2222"}' if "headRefOid" in args else "",
        "stderr": "",
    })()

    class A:
        pr = 99
        force = False
        no_subs = True
        self_merge = False
        note = None
        require_ci = False
        batch_verified = True
        json = False
        verdict_ev = ("prerealign12", "c7")

    tal.run = _run_stub_ancestor(True)          # merge thật ⇒ head đã vào base
    tal.cmd_merge(A())      # KHÔNG Fail dù merge_blockers khai thiếu verdict
    check(any("prerealign12" in (w or "") for w in written),
          "sổ mang sha bắt TRƯỚC realign", str(written))


def test_2300_run_stage_symptom_exit_code():
    print("run_stage: triệu chứng CỔNG HỎNG → exit GATE_BROKEN=3, không phải mã vị trí gọi (#2300 C3)")
    with tempfile.TemporaryDirectory() as d:
        try:
            tal.run_stage(Path(d), ["echo 'PHP Fatal: autoload.php missing'; exit 1"],
                          "suite ĐỎ:", 2)
            check(False, "phải Fail")
        except tal.Fail as e:
            check(e.code == tal.GATE_BROKEN, "mã 3 (cổng hỏng)", f"code={e.code}")
            check("CỔNG HỎNG" in str(e), "nhãn đúng loại")
        try:
            tal.run_stage(Path(d), ["echo 'FAILED tests: 1'; exit 1"], "suite ĐỎ:", 2)
            check(False, "phải Fail")
        except tal.Fail as e:
            check(e.code == 2, "không triệu chứng → giữ mã của vị trí gọi (2)", f"code={e.code}")


def test_2300_push_with_lease_pins_value():
    print("push_with_lease: lease theo GIÁ TRỊ sha remote, không theo tracking ref (#2300 F5)")
    pushes = []

    def fake_run(args, cwd=None, check=True, stdin=None):
        if args[:2] == ["git", "ls-remote"]:
            return type("R", (), {"returncode": 0,
                                  "stdout": "deadbeefcafe refs/heads/issue-9\n", "stderr": ""})()
        pushes.append(args)
        return type("R", (), {"returncode": 0, "stdout": "", "stderr": ""})()

    tal.run = fake_run
    tal.push_with_lease("/tmp", "issue-9")
    check(any("--force-with-lease=issue-9:deadbeefcafe" in a for a in pushes[0]),
          "expect = sha vừa đọc từ remote", str(pushes[0]))

    pushes.clear()

    def fake_run2(args, cwd=None, check=True, stdin=None):
        if args[:2] == ["git", "ls-remote"]:
            return type("R", (), {"returncode": 0, "stdout": "", "stderr": ""})()
        pushes.append(args)
        return type("R", (), {"returncode": 0, "stdout": "", "stderr": ""})()

    tal.run = fake_run2
    tal.push_with_lease("/tmp", "issue-9")
    check(any("--force-with-lease=issue-9:" in a for a in pushes[0]),
          "branch chưa có trên remote → expect-trống (chỉ push nếu vẫn chưa có)", str(pushes[0]))


def test_2300_reopened_after_multipage():
    print("reopened_after: --paginate nhiều trang không còn là 'đã reopen vĩnh viễn' (#2300 C9)")
    page1 = json.dumps([{"event": "labeled", "created_at": "2026-01-01T00:00:00Z"}])
    page2 = json.dumps([{"event": "reopened", "created_at": "2026-03-01T00:00:00Z"}])
    tal.gh = lambda args, check=True, stdin=None: type(
        "R", (), {"returncode": 0, "stdout": page1 + "\n" + page2, "stderr": ""})()
    check(tal.reopened_after(9, "2026-02-01T00:00:00Z") is True, "reopen SAU merge → True")
    check(tal.reopened_after(9, "2026-04-01T00:00:00Z") is False, "reopen TRƯỚC merge → False")
    tal.gh = lambda args, check=True, stdin=None: type(
        "R", (), {"returncode": 1, "stdout": "", "stderr": "boom"})()
    check(tal.reopened_after(9, "2026-02-01T00:00:00Z") is True, "không đọc được → fail-safe True")


def test_2300_gc_keeps_unpushed_commits():
    print("cmd_gc: rào 'còn thứ chưa tới base' đứng TRƯỚC nhánh xoá (#2300 A12 / #2674)")
    src = (HERE / "tal").read_text()
    blk = src[src.index("def cmd_gc("):]
    blk = blk[:blk.index("\ndef ", 10)]
    # Ghim VỊ TRÍ ở đây; ghim HÀNH VI ở test_gc_keeps_a_worktree_by_content_not_by_sha_ancestry.
    # Rào phải chạy TRƯỚC khi `remove_worktree` gọi `branch -D` — bản sao cuối cùng.
    # Neo vào TÊN HÀM, không vào danh sách tham số: bản trước ghim nguyên chuỗi
    # `worktree_unmerged_content(wt, BASE_BRANCH)` và đứt ngay khi #2792 thêm
    # `merged_head_sha=`. Bài này đo THỨ TỰ, nên chữ ký hàm không phải việc của
    # nó — ghim chữ ký ở đây chỉ bắt người thêm tham số phải sửa một rào không
    # liên quan, và lần sau họ sẽ nới nó ra cho xong.
    check("worktree_unmerged_content(" in blk,
          "gc hỏi 'còn nội dung nào chưa tới base'")
    check(blk.index("worktree_unmerged_content(")
          < blk.index('"xoá worktree + branch cục bộ'),
          "phép đo đứng TRƯỚC nhánh xoá")
    # Ratchet #2674: phép đo cũ hỏi TỔ TIÊN THEO SHA, mà repo này squash-merge nên
    # nó đúng với 100% worktree gc gặp — đừng dựng lại nó ở chỗ này.
    check('f"origin/{BASE_BRANCH}..HEAD"' not in blk,
          "KHÔNG quay lại đo tổ tiên SHA trong gc (#2674)")



def _gc_abandoned_harness(*, keep_reason, remote_branch=True, worktree=False):
    """Dựng `cmd_gc` chạy tới đúng nhánh 'PR đóng, KHÔNG merge'.

    → (chạy, danh sách lệnh gh). `keep_reason` là thứ phép đo nội dung trả về.
    """
    calls: list[list[str]] = []

    def fake_gh(args, check=True, stdin=None):
        calls.append(args)
        return type("R", (), {"returncode": 0, "stdout": "", "stderr": ""})()

    def fake_gh_json(args, default=None):
        if "--state" in args and "closed" in args:
            return [{"number": 42, "headRefName": "issue-2993", "mergedAt": None}]
        return [] if isinstance(default, list) else (default or [])

    # `cmd_gc` hỏi git thật cho `main_worktree`; stub `run` trả rỗng nên phải
    # nạp sẵn cache của Ctx thay vì để nó đi dò.
    tal.Ctx._main = Path("/tmp/tal-gc-test")
    tal.Ctx._root = Path("/tmp/tal-gc-test")

    tal.gh = fake_gh
    tal.gh_json = fake_gh_json
    tal.gh_json_required = lambda args: fake_gh_json(args, [])
    tal.run = lambda *a, **k: type("R", (), {"returncode": 0, "stdout": "", "stderr": ""})()
    tal.reap_batch_gate = lambda dry: []
    tal.reap_leases = lambda dry: []
    tal.cleanup_orphan_worktrees = lambda dry: []
    tal.delete_merged_branches = lambda repo, dry, protect: []
    tal.stranded_review_candidates = lambda *a, **k: []
    tal.branch_exists_remote = lambda br, cwd=None: remote_branch
    tal.branch_exists_local = lambda br: False
    tal.worktree_paths_for_issue = lambda i: ([tal.C.worktrees_dir / f"issue-{i}"]
                                              if worktree else [])
    tal.worktree_unmerged_content = lambda *a, **k: keep_reason
    tal.remove_worktree = lambda i: True

    class A:
        dry_run = False
        include_abandoned = True
        no_submodules = True
        json = False

    return (lambda: tal.cmd_gc(A())), calls


def _deleted_refs(calls) -> list[str]:
    return [" ".join(a) for a in calls
            if "DELETE" in a and any("refs/heads/" in x for x in a)]


def test_2993_gc_abandoned_refuses_when_branch_still_carries_content():
    print("#2993 ĐƯỜNG THẬT: nhánh còn nội dung chưa vào base ⇒ KHÔNG xoá ref")

    # Ghim HÀNH VI, không ghim chữ. Bản trước quét nguồn nên tắt rào bằng
    # `if False and ...` vẫn xanh cả 138 ca — rào bị tắt hoàn toàn mà không ai
    # biết. Đây là cùng khuôn với ca ở #2988.
    go, calls = _gc_abandoned_harness(keep_reason="còn 2 commit chưa tới dev")
    go()

    check(_deleted_refs(calls) == [], "KHÔNG xoá ref nào", str(_deleted_refs(calls)))
    restore_tal()


def test_2993_gc_abandoned_measures_the_REMOTE_REF_not_the_worktree():
    print("#2993 KHÔNG có worktree cục bộ ⇒ vẫn phải đo, vì thứ bị xoá là ref remote")

    # Lỗ thật của vòng 1: phép đo chỉ chạy khi worktree tồn tại, nên trên máy
    # không giữ worktree thì `gc --include-abandoned` xoá ref mà không đo gì.
    # Và nó không hiếm — chính `gc` là thứ dọn worktree đi.
    measured: list[dict] = []

    go, calls = _gc_abandoned_harness(keep_reason="ref còn commit chưa tới dev",
                                      remote_branch=True, worktree=False)
    tal.worktree_unmerged_content = lambda *a, **k: (measured.append(k), k.get("rev"))[1] \
        and "ref còn commit chưa tới dev"
    go()

    check(_deleted_refs(calls) == [], "KHÔNG xoá ref khi không có worktree", str(calls))
    check(any(m.get("rev", "").startswith("origin/") for m in measured),
          "hỏi về ORIGIN/<nhánh>, không phải HEAD của worktree", str(measured))
    restore_tal()


def test_2993_gc_abandoned_still_deletes_a_branch_that_holds_nothing():
    print("#2993 nhánh không còn gì riêng ⇒ dọn y như hôm nay (rào phải biết IM)")

    go, calls = _gc_abandoned_harness(keep_reason=None)
    go()

    check(any("refs/heads/issue-2993" in d for d in _deleted_refs(calls)),
          "vẫn xoá nhánh đã hợp nhất hết", str(calls))
    restore_tal()


def test_2299_newest_verdict_wins_per_sha():
    print("verdict evidence: changes MỚI HƠN trên cùng sha giết pass (#2299/F5)")
    head = "abc123def4567890aaaa"
    prefix = head[:12]
    box = {"comments": []}
    tal.gh_json = lambda args, default=None: ({"comments": box["comments"]}
                                              if "comments" in args else {"headRefOid": head})
    # pass cũ → changes mới hơn CÙNG sha ⇒ hết bằng chứng
    box["comments"] = [
        {"id": "c1", "body": f"<!-- tal:review verdict=pass round=1 sha={prefix} -->OK"},
        {"id": "c2", "body": f"<!-- tal:review verdict=changes round=2 sha={prefix} -->SỬA"},
    ]
    check(tal.pr_verdict_pass_evidence(9, head) == (None, None),
          "pass rồi changes cùng sha → KHÔNG còn evidence")
    # changes cũ → pass mới hơn ⇒ evidence sống
    box["comments"] = [
        {"id": "c1", "body": f"<!-- tal:review verdict=changes round=1 sha={prefix} -->SỬA"},
        {"id": "c2", "body": f"<!-- tal:review verdict=pass round=2 sha={prefix} -->OK"},
    ]
    vsha, vcid = tal.pr_verdict_pass_evidence(9, head)
    check(vsha == prefix and vcid == "c2", "changes rồi pass → evidence là pass mới", f"{vsha},{vcid}")


def test_2299_realign_only_delta_keeps_verdict():
    print("verdict evidence: head là commit căn-pointer ⇒ verdict sha trước còn hiệu lực (#2299)")
    head = "ffff0000111122223333"
    reviewed = "aaaa111122223333bbbb"[:12]
    box = {"comments": [
        {"id": "c9", "body": f"<!-- tal:review verdict=pass round=3 sha={reviewed} -->OK"},
    ]}
    tal.gh_json = lambda args, default=None: ({"comments": box["comments"]}
                                              if "comments" in args else {"headRefOid": head})
    tal.submodules = lambda: {"pos-web": {"path": "pos-web"}, "ws": {"path": "workstation-app"}}
    # C.main_worktree đừng đi qua git thật (run đã bị stub) — ghim thẳng rồi trả lại
    tal.Ctx._main = Path("/tmp")
    tal.Ctx._root = Path("/tmp")

    def run_realign(args, cwd=None, check=True, stdin=None):
        out = ""
        if args[:2] == ["git", "log"]:
            out = "chore(submodule): căn pointer lên tip sau khi merge PR con\n"
        elif args[:2] == ["git", "diff"]:
            out = "pos-web\n"
        return type("R", (), {"returncode": 0, "stdout": out, "stderr": ""})()

    tal.run = run_realign
    vsha, vcid = tal.pr_verdict_pass_evidence(9, head, head_branch="issue-9")
    check(vsha == reviewed and vcid == "c9",
          "delta realign-only → evidence của sha đã review", f"{vsha},{vcid}")

    # chiều ngược: có một commit KHÔNG phải realign ⇒ không được mượn verdict cũ
    def run_mixed(args, cwd=None, check=True, stdin=None):
        out = ""
        if args[:2] == ["git", "log"]:
            out = ("chore(submodule): căn pointer lên tip sau khi merge PR con\n"
                   "fix: sửa thêm một tí\n")
        elif args[:2] == ["git", "diff"]:
            out = "pos-web\nbackend/app/Foo.php\n"
        return type("R", (), {"returncode": 0, "stdout": out, "stderr": ""})()

    tal.run = run_mixed
    check(tal.pr_verdict_pass_evidence(9, head, head_branch="issue-9") == (None, None),
          "delta có commit thật → KHÔNG mượn verdict cũ")

    # diff lấn ra ngoài gitlink dù subject đúng khuôn ⇒ vẫn từ chối
    def run_badpaths(args, cwd=None, check=True, stdin=None):
        out = ""
        if args[:2] == ["git", "log"]:
            out = "chore(submodule): căn pointer lên tip sau khi merge PR con\n"
        elif args[:2] == ["git", "diff"]:
            out = "backend/app/Foo.php\n"
        return type("R", (), {"returncode": 0, "stdout": out, "stderr": ""})()

    tal.run = run_badpaths
    check(tal.pr_verdict_pass_evidence(9, head, head_branch="issue-9") == (None, None),
          "subject giả khuôn nhưng diff chạm ngoài gitlink → từ chối")

    # ca phân biệt cho RIÊNG subject-check: diff CHỈ gitlink nhưng commit là bump
    # TAY (người chọn pointer, không phải realign máy tính ra) → vẫn phải từ chối
    def run_manual_bump(args, cwd=None, check=True, stdin=None):
        out = ""
        if args[:2] == ["git", "log"]:
            out = "fix: bump pointer pos-web tay\n"
        elif args[:2] == ["git", "diff"]:
            out = "pos-web\n"
        return type("R", (), {"returncode": 0, "stdout": out, "stderr": ""})()

    tal.run = run_manual_bump
    check(tal.pr_verdict_pass_evidence(9, head, head_branch="issue-9") == (None, None),
          "bump pointer TAY (diff chỉ gitlink, subject khác khuôn) → KHÔNG mượn verdict cũ")
    tal.Ctx._main = tal.Ctx._root = None

# ─────────────────────────────────────────────────────────────────────────────
# #2348 — bề mặt cấu hình phải THẬT SỰ điều khiển hành vi, không chỉ được in ra
# ─────────────────────────────────────────────────────────────────────────────

def test_config_surface_is_wired_not_decorative():
    print("agent-loop.json điều khiển hằng số (#2348)")

    import json as _json
    import tempfile

    # Trước #2348 file config khai 12 khoá mà `tal` chỉ đọc 4. Một repo khác sửa
    # `labels.ready` sẽ không thấy tác dụng gì và không có gì báo cho họ biết —
    # đúng loại "rào rỗng" trả lời CÓ cho câu hỏi "chỗ này cấu hình được chưa".
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        (root / ".claude").mkdir()
        (root / ".claude" / "agent-loop.json").write_text(_json.dumps({
            "baseBranch": "trunk",
            "promotionBranch": "release",
            "ttlSeconds": 111,
            "maxAttempts": 9,
            "refNamespace": "refs/other/leases/",
            "severityOrder": ["blocker", "major"],
            "labels": {"ready": "bot:go", "changesRequested": "bot:fix"},
        }))
        cwd0 = os.getcwd()
        try:
            os.chdir(root)
            fresh = load_tal()
        finally:
            os.chdir(cwd0)

    check(fresh.BASE_BRANCH == "trunk", "baseBranch từ config", fresh.BASE_BRANCH)
    check(fresh.PROMOTION_BRANCH == "release", "promotionBranch từ config", fresh.PROMOTION_BRANCH)
    check(fresh.TTL == 111, "ttlSeconds từ config", str(fresh.TTL))
    check(fresh.MAX_ATTEMPTS == 9, "maxAttempts từ config", str(fresh.MAX_ATTEMPTS))
    check(fresh.REF_NS == "refs/other/leases/", "refNamespace từ config", fresh.REF_NS)
    check(fresh.L_READY == "bot:go", "labels.ready từ config", fresh.L_READY)
    check(fresh.L_CHANGES == "bot:fix", "labels.changesRequested từ config", fresh.L_CHANGES)
    check(fresh.SEVERITY_RANK == {"blocker": 0, "major": 1},
          "severityOrder từ config", str(fresh.SEVERITY_RANK))

    # Khoá KHÔNG khai phải rơi về mặc định, không thành None/rỗng — một nửa config
    # thiếu là chuyện thường ở repo mới.
    check(fresh.L_SHIPPED == "status:shipped", "khoá thiếu → mặc định", fresh.L_SHIPPED)

    # Không có file config nào ⇒ vẫn chạy được bằng mặc định (repo chưa cài).
    with tempfile.TemporaryDirectory() as td:
        cwd0 = os.getcwd()
        try:
            os.chdir(td)
            bare = load_tal()
        finally:
            os.chdir(cwd0)
    check(bare.BASE_BRANCH == "dev" and bare.L_READY == "agent:ready",
          "không có agent-loop.json → mặc định, không nổ")

    sys.modules["tal"] = tal          # trả module về bản thật cho các ca sau


def test_config_command_exists_and_reports_sources():
    print("tal config (#2348 — Bước 0 của cả hai skill gọi lệnh này)")

    # Lệnh này TỪNG KHÔNG TỒN TẠI: `tal config` trả `argparse: invalid choice`,
    # trong khi cả `issue-work` lẫn `issue-review` bảo chạy nó trước khi sửa dòng
    # đầu tiên. Ca này ghim sự tồn tại của nó.
    check(callable(getattr(tal, "cmd_config", None)), "cmd_config tồn tại")

    import io
    from contextlib import redirect_stdout

    class A:
        json = True

    buf = io.StringIO()
    with redirect_stdout(buf):
        tal.cmd_config(A())
    import json as _json
    out = _json.loads(buf.getvalue())

    for key in ("values", "sources", "labels", "policyDocs", "riskDomains"):
        check(key in out, f"tal config --json có `{key}`")

    # Cột NGUỒN là phần quan trọng nhất: nó cho biết khoá vừa sửa có tác dụng không.
    check(set(out["sources"].values()) <= {"config", "mặc định"} or
          any(s.startswith("env ") for s in out["sources"].values()),
          "mỗi giá trị khai rõ nguồn env/config/mặc định", str(out["sources"]))


def test_session_id_distinguishes_nested_agents():
    """#2369 — agent lồng nhau THỪA KẾ biến danh tính của tiến trình cha.

    Đo được trên codex-cli 0.147.0: `codex exec` mang theo cả
    `CLAUDE_CODE_SESSION_ID` của phiên Claude gọi nó, bên cạnh
    `CODEX_THREAD_ID` của chính nó. Cha và con cùng khai một danh tính ⇒ lease
    mất đúng khả năng loại trừ mà nó sinh ra để có.

    Xếp hạng ưu tiên giữa hai biến KHÔNG sửa được việc này: chiều lồng nhau nào
    cũng có thật (Claude trong Codex y như Codex trong Claude), nên thứ tự tĩnh
    nào cũng đúng một chiều và sai chiều kia. Cái đo ở đây là bất biến thật sự
    cần: **cha và con phải ra hai danh tính khác nhau**, bất kể ai lồng ai.
    """
    # Cùng lý do #2400: nguồn danh tính lấy từ `tal`, không chép tay.
    keys = (tal._SESSION_OVERRIDE_VAR, *tal._AGENT_SESSION_VARS)
    saved = {k: os.environ.get(k) for k in keys}

    def setenv(**kw):
        for k in keys:
            os.environ.pop(k, None)
        for k, v in kw.items():
            os.environ[k] = v

    try:
        # Ca thật đã đo: Codex bên trong Claude. Con thấy CẢ HAI biến, cha chỉ
        # thấy biến của mình — hai danh tính phải khác nhau.
        setenv(CLAUDE_CODE_SESSION_ID="claude-parent")
        parent = tal.session_id()
        setenv(CODEX_THREAD_ID="codex-thread", CLAUDE_CODE_SESSION_ID="claude-parent")
        child = tal.session_id()
        assert child != parent, (
            "Codex lồng trong Claude phải khác danh tính với phiên cha; cả hai đều %r"
            % child)

        # Chiều NGƯỢC LẠI — Claude lồng trong Codex. Cha là phiên Codex.
        setenv(CODEX_THREAD_ID="codex-outer")
        parent_rev = tal.session_id()
        setenv(CODEX_THREAD_ID="codex-outer", CLAUDE_CODE_SESSION_ID="claude-inner")
        child_rev = tal.session_id()
        assert child_rev != parent_rev, (
            "Claude lồng trong Codex phải khác danh tính với phiên cha; cả hai đều %r"
            % child_rev)

        # Danh tính phải ỔN ĐỊNH giữa các lượt gọi, nếu không lease tự mất chủ.
        assert tal.session_id() == child_rev

        # Không phụ thuộc chiều lồng: cùng tập biến ⇒ cùng danh tính.
        setenv(CLAUDE_CODE_SESSION_ID="claude-inner", CODEX_THREAD_ID="codex-outer")
        assert tal.session_id() == child_rev

        # Hai cặp lồng nhau KHÁC nhau không được đụng nhau ở 8 ký tự đầu — mọi
        # chỗ hiển thị/so khớp trong tal đều cắt [:8] (bài học #2300).
        setenv(CODEX_THREAD_ID="codex-outer", CLAUDE_CODE_SESSION_ID="claude-other")
        other = tal.session_id()
        assert other[:8] != child_rev[:8], (other, child_rev)

        # Ghi đè tay thắng tất cả.
        setenv(TAL_SESSION="manual", CODEX_THREAD_ID="codex-thread",
               CLAUDE_CODE_SESSION_ID="claude-parent")
        assert tal.session_id() == "manual"

        # Claude chạy một mình vẫn như cũ.
        setenv(CLAUDE_CODE_SESSION_ID="claude-only")
        assert tal.session_id() == "claude-only"

        # Không có nguồn nào → băm, và KHÔNG rỗng (danh tính rỗng = mọi phiên trùng nhau).
        setenv()
        fallback = tal.session_id()
        assert fallback.startswith("shell") and len(fallback) > 8, fallback
    finally:
        for k, v in saved.items():
            if v is None:
                os.environ.pop(k, None)
            else:
                os.environ[k] = v
def test_2573_promotion_base_is_refused_without_promote():
    print("rào base (#2573): PR bị GitHub kéo sang nhánh phát hành KHÔNG merge được bằng --force")

    v = tal.promotion_base_verdict

    # Phần thuần tuý — mọi tổ hợp, không mạng.
    check(v("dev", False, "dev", "main") is None, "base=dev ⇒ qua")
    check(v("dev", True, "dev", "main") is None, "base=dev + --promote ⇒ vẫn qua")
    check(v(None, False, "dev", "main") is None,
          "không đọc được base ⇒ QUA (rào này chống một tai nạn, không chống mạng hỏng)")
    check(v("main", False, "dev", "main") == "promotion",
          "base=main không khai --promote ⇒ CHẶN")
    check(v("main", True, "dev", "main") is None,
          "base=main + --promote ⇒ qua, vì người gọi đã nói ra ý định")
    check(v("release/x", False, "dev", "main") == "foreign", "base lạ ⇒ CHẶN")
    check(v("release/x", True, "dev", "main") == "foreign",
          "--promote KHÔNG mở base lạ — nó khẳng định 'tôi đang phát hành', "
          "không phải 'kệ mọi kiểm base'")

    # Cửa thật: chặn TRƯỚC khi chạm bất cứ thứ gì, và `--force` không mở được.
    calls = []
    tal.gh_json = lambda args, default=None: {"baseRefName": "main"}
    tal.gh = lambda args, check=True, stdin=None: calls.append(args)

    for force in (False, True):
        args = argparse.Namespace(pr=42, force=force, no_subs=False, self_merge=False,
                                  note="lý do", require_ci=False, batch_verified=False,
                                  json=False, promote=False)
        # Bắt RỘNG, không chỉ `tal.Fail`. Rào bị gỡ thì dòng chảy đi tiếp vào
        # `ref_exists()` và chết bằng `AttributeError` (test chỉ giả `gh`, không
        # giả `run`) — vẫn đỏ, nhưng người đọc nhận một stack trace thay vì câu
        # "rào không chặn". Một rào mà thông điệp hỏng thì lần sau bị gỡ nhầm.
        try:
            tal.cmd_merge(args)
            check(False, f"--force={force} ⇒ phải NÉM", "không ném gì cả")
        except tal.Fail as e:
            check("--promote" in str(e) and "gh pr edit" in str(e),
                  f"--force={force} ⇒ chặn, kèm CẢ HAI đường đi tiếp", str(e)[:80])
        except Exception as e:                                   # noqa: BLE001
            check(False, f"--force={force} ⇒ chặn bằng Fail",
                  f"RÀO KHÔNG CHẶN — dòng chảy đi tiếp rồi chết ở {type(e).__name__}: {e}")

    check(calls == [], "không lệnh `gh` ghi nào được gọi trước khi chặn", str(calls))


# ─────────────────────────────────────────────────────────────────────────────
# #2909 — promote dev -> main không được xoá file chỉ main có
# ─────────────────────────────────────────────────────────────────────────────

def test_2909_promotion_gate_ci_and_tal_measure_the_same_thing():
    print("rào promote (#2909): cổng CI và `tal` phải đo CÙNG một thứ")

    # Rào này sống ở HAI chỗ, và đó là cố ý: `tal merge` chặn đường vòng lặp
    # agent, còn `.github/workflows/promote-gate.yml` chặn đường `gh pr merge`
    # gõ tay — hai lượt promote gần nhất (#2957, #2966) đều đi đường sau, nên
    # bản trong `tal` không hề chạm vào chúng.
    #
    # Hai bản cài thì trôi được. Ghim đúng phần NGỮ NGHĨA: hướng so và bộ lọc.
    # Đảo hướng hay đổi `A` thành `D` đều làm cổng im lặng báo sạch, và một
    # cổng báo sạch nguy hiểm hơn một cổng không tồn tại.
    # Neo vào vị trí THẬT của `tal`, không vào HERE: ca canary #2202 chạy một
    # bản sao của file này trong thư mục tạm và chỉ symlink `tal` sang cạnh nó,
    # nên HERE lúc đó là thư mục tạm và mọi đường dẫn tương đối trỏ ra hư không.
    # `.resolve()` đi qua symlink về đúng cây repo.
    repo_root = Path(tal.__file__).resolve().parents[3]
    raw = (repo_root / ".github/workflows/promote-gate.yml").read_text(encoding="utf-8")

    # Đo trên phần LỆNH, bỏ comment. File này giải thích từng lựa chọn bằng
    # tiếng Việt và các comment ấy nhắc lại nguyên văn `--no-renames`,
    # `--diff-filter=A`, `paths:`… Bản đầu của bài này so trên text thô nên
    # đột biến "gỡ --no-renames khỏi lệnh" vẫn XANH: chuỗi còn sống trong
    # comment. Đúng họ với bẫy đã trả giá ở #2971 — so nguyên văn trả lời sai
    # câu hỏi.
    def directives(text):
        return "\n".join(line for line in text.splitlines()
                          if line.strip() and not line.lstrip().startswith("#"))

    workflow = directives(raw)

    check("origin/dev..origin/main" in workflow,
          "cổng CI so đúng hướng dev..main (main có, dev không)")
    check("origin/main..origin/dev" not in workflow,
          "cổng CI KHÔNG đo chiều ngược — đó là nội dung đang promote")
    check("--diff-filter=A" in workflow, "cổng CI lọc đúng path CHỈ main có")
    check("--no-renames" in workflow,
          "cổng CI hỏi path có tồn tại không, không nhờ Git đoán đổi tên")
    check("-z" in workflow, "cổng CI đọc theo NUL — đã có path chứa khoảng trắng")

    # Không `paths:` — rào này phát biểu về file KHÔNG nằm trong diff của PR,
    # tức đúng thứ một bộ lọc theo path không nhìn thấy được (#2971).
    trigger = workflow[workflow.index("on:"):workflow.index("concurrency:")]
    check("paths:" not in trigger,
          "cổng CI KHÔNG bị `paths:` lọc — thêm vào là tự tắt tiếng nó", trigger)

    # Hotfix `issue-<n> → main` là đường TẠO RA chênh lệch, không được tự chặn
    # chính nó; nếu không, một sự cố production sẽ không vá được.
    check("github.head_ref == 'dev'" in workflow,
          "cổng CI chỉ áp cho PR promote, không chặn hotfix đi thẳng")


def test_2909_promotion_gate_fails_closed_when_git_cannot_answer():
    print("rào promote (#2909): git không trả lời được ⇒ CHẶN, không đoán bằng ref cũ")

    # Vì sao cần bài riêng: bài dưới gọi `refresh=False` nên KHÔNG bao giờ đi
    # qua nhánh fetch. Đã đo bằng đột biến — đổi `if fetched.returncode != 0`
    # thành `if False` (tức fetch hỏng thì im lặng dùng ref cũ) và **cả 128 ca
    # vẫn xanh**. Chính docstring của hàm nói ref cũ sẽ biến cổng an toàn này
    # thành một thứ nhắc-bằng-trí-nhớ, mà khẳng định đó chưa có gì giữ.
    #
    # Đây là loại hỏng tệ nhất cho một rào: nó KHÔNG kêu và KHÔNG chặn — nó báo
    # "sạch" trên dữ liệu cũ, đúng lúc người vận hành cần nó nhất.
    original_run = tal.run
    try:
        for broken, label in (("fetch", "không làm mới được ref"),
                              ("diff", "không so được hai nhánh")):
            seen = []

            def fake_run(cmd, cwd=None, check=True, stdin=None, _broken=broken):
                seen.append(cmd[:2])
                failed = (cmd[:2] == ["git", _broken])
                return types.SimpleNamespace(
                    returncode=1 if failed else 0,
                    stdout="", stderr="fatal: mạng hỏng" if failed else "")

            tal.run = fake_run
            try:
                tal.promotion_only_files("dev", "main", "/nonexistent", refresh=True)
            except tal.Fail as exc:
                check("KHÔNG thể chứng minh" in str(exc),
                      f"{label} ⇒ Fail nói rõ là KHÔNG chứng minh được", str(exc))
                check(exc.code == 2, f"{label} ⇒ exit code 2", str(exc.code))
            else:
                check(False, f"{label} mà cổng vẫn cho qua — rào báo sạch trên dữ liệu cũ")

            # Fetch hỏng thì KHÔNG được chạy tiếp sang diff: kết quả của diff lúc
            # đó đọc trên ref cũ, tức đúng thứ đang cần chứng minh là không dùng.
            if broken == "fetch":
                check(["git", "diff"] not in seen,
                      "fetch hỏng ⇒ dừng ngay, không đo tiếp trên ref cũ", str(seen))
    finally:
        tal.run = original_run


def test_2909_promotion_refuses_files_that_exist_only_on_main():
    print("rào promote (#2909): file chỉ main có phải CHẶN trước merge")

    # Phép đo Git thật, hai chiều. Tên có khoảng trắng ghim luôn việc parse theo
    # NUL: split theo dòng/space sẽ báo sai path và hướng dẫn cherry-pick sai.
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        _git("init", "-q", "-b", "dev", str(root))
        for k, v in (("user.email", "tal-test@example.com"), ("user.name", "tal test"),
                     ("commit.gpgsign", "false")):
            _git("-C", str(root), "config", k, v)
        (root / "common.txt").write_text("common\n")
        _git("-C", str(root), "add", "common.txt")
        _git("-C", str(root), "commit", "-q", "-m", "common")
        common = tal.run(["git", "-C", str(root), "rev-parse", "HEAD"]).stdout.strip()

        (root / "dev-only.txt").write_text("normal promotion content\n")
        _git("-C", str(root), "add", "dev-only.txt")
        _git("-C", str(root), "commit", "-q", "-m", "dev work")
        dev = tal.run(["git", "-C", str(root), "rev-parse", "HEAD"]).stdout.strip()

        _git("-C", str(root), "checkout", "-q", "-b", "main", common)
        (root / "react crash guard.test.tsx").write_text("hotfix test\n")
        _git("-C", str(root), "add", "react crash guard.test.tsx")
        _git("-C", str(root), "commit", "-q", "-m", "production hotfix")
        main = tal.run(["git", "-C", str(root), "rev-parse", "HEAD"]).stdout.strip()
        _git("-C", str(root), "update-ref", "refs/remotes/origin/dev", dev)
        _git("-C", str(root), "update-ref", "refs/remotes/origin/main", main)

        found = tal.promotion_only_files("dev", "main", str(root), refresh=False)
        check(found == ["react crash guard.test.tsx"],
              "chỉ trả file main-only; dev-only là nội dung promote bình thường", str(found))

        # Sau khi đưa hotfix về dev, cổng phải IM. Một rào chỉ biết chặn sẽ buộc
        # người vận hành bypass rồi sớm muộn cũng bị gỡ.
        _git("-C", str(root), "checkout", "-q", "dev")
        (root / "react crash guard.test.tsx").write_text("hotfix test\n")
        _git("-C", str(root), "add", "react crash guard.test.tsx")
        _git("-C", str(root), "commit", "-q", "-m", "backport hotfix")
        dev_reconciled = tal.run(
            ["git", "-C", str(root), "rev-parse", "HEAD"]
        ).stdout.strip()
        _git("-C", str(root), "update-ref", "refs/remotes/origin/dev", dev_reconciled)
        check(tal.promotion_only_files("dev", "main", str(root), refresh=False) == [],
              "hotfix đã có ở dev ⇒ cổng im")

    # Cửa thật trong cmd_merge: `--force` cũng không được mở. Chặn phải xảy ra
    # trước review/CI/submodule/gh merge, nên chỉ cần giả đúng hai nguồn đầu.
    for force in (False, True):
        calls = []
        tal.gh_json = lambda args, default=None: {
            "baseRefName": "main", "headRefName": "dev",
        }
        tal.promotion_only_files = lambda *a, **k: [
            "web/pos/src/app/pos/components/order-cart-hooks.arch.test.ts",
            "web/pos/src/app/pos/components/order-cart-load-lifecycle.test.tsx",
        ]
        tal.gh = lambda args, check=True, stdin=None: calls.append(args)
        args = argparse.Namespace(pr=2908, force=force, no_subs=False,
                                  self_merge=False, note="", require_ci=False,
                                  batch_verified=False, json=False, promote=True,
                                  ci_red=False)
        try:
            tal.cmd_merge(args)
            check(False, f"--force={force} ⇒ main-only phải CHẶN")
        except tal.Fail as e:
            msg = str(e)
            check("2 file" in msg and "cherry-pick" in msg and
                  "order-cart-load-lifecycle.test.tsx" in msg,
                  f"--force={force} ⇒ chặn kèm danh sách + cách sửa", msg)
        check(calls == [], f"--force={force} ⇒ chưa gọi gh merge", str(calls))


# ─────────────────────────────────────────────────────────────────────────────
# #2639 — CI ĐỎ chặn merge, và `--force` KHÔNG mở
# ─────────────────────────────────────────────────────────────────────────────

def _merge_args(**kw):
    base = dict(pr=42, force=False, no_subs=True, self_merge=False, note=None,
                require_ci=False, batch_verified=False, json=False, promote=False,
                ci_red=False)
    base.update(kw)
    return argparse.Namespace(**base)


def _stub_merge_env(why, calls):
    """Dựng đủ để `cmd_merge` chạy tới CỬA CI rồi (nếu qua) tới `gh pr merge`."""
    class R:
        stdout, stderr, returncode = "", "", 0

    tal.gh_json = lambda args, default=None: {"baseRefName": "dev"}
    tal.gh = _gh_stub_with_head(calls)
    # #2988 — merge THẬT: head phải là tổ tiên của base, nếu không rào mới chặn.
    tal.run = _run_stub_ancestor(True)
    tal.ref_exists = lambda key: False
    tal.merge_blockers = lambda pr, require_ci=True: (None, list(why), ("abc123abc123", "9"))
    tal.pr_checks = lambda pr: ("fail", ["arch-gate=fail"]) if why else ("pass", [])
    tal.pr_dangling_pointers = lambda pr: []
    tal.session_id = lambda: "deadbeefcafe0000"
    # Chẩn đoán base đỏ đi ra mạng — test này đo RÀO, không đo chẩn đoán.
    tal.base_red_hint = lambda names: ""


def test_2639_red_ci_refuses_merge_and_force_does_not_open_it():
    print("rào CI (#2639): check ĐỎ chặn merge, `--force` không mở, chỉ `--ci-red --note` mở")

    # ── phần thuần tuý ────────────────────────────────────────────────────────
    check(tal.red_check_names(["arch-gate=fail", "web/admin=pass", "pest=skipping"])
          == ["arch-gate"], "chỉ lọc ra check ĐỎ, bỏ xanh/skipping")
    check(tal.red_check_names(["build · vet · gofmt · test=cancel"])
          == ["build · vet · gofmt · test"], "tên check có dấu `=` phía trước vẫn cắt đúng")
    check(tal.red_check_names(["arch-gate=pass"]) == [], "không có gì đỏ ⇒ rỗng")

    v = tal.ci_red_verdict
    check(v([tal.CI_RED + "arch-gate"], False) == ["arch-gate"],
          "blocker CI ⇒ chặn, kèm mô tả")
    # Tên check của repo này CHỨA " · " (`build · vet · gofmt · test`). Tách payload
    # theo dấu đó là bịa ra bốn check không tồn tại rồi đi hỏi GitHub về chúng.
    check(v([tal.CI_RED + "build · vet · gofmt · test"], False)
          == ["build · vet · gofmt · test"],
          "KHÔNG cắt vụn tên check chứa ` · `")
    check(v([tal.CI_RED + "arch-gate"], True) == [],
          "--ci-red ⇒ cho qua (lời khẳng định về Ý ĐỊNH, khác hẳn --force)")
    check(v(["chưa có nhãn agent:review-passed", "PR đang là draft"], False) == [],
          "blocker KHÁC không bị nhầm thành CI đỏ")
    check(v([], False) == [], "không blocker nào ⇒ rỗng")

    # ── `merge_blockers` gọi TÊN check đỏ, không in cả bảng ────────────────────
    tal.gh_json = lambda args, default=None: {
        "isDraft": False, "mergeable": "MERGEABLE", "state": "OPEN",
        "headRefName": "issue-1", "headRefOid": "abc123abc123", "labels": []}
    tal.pr_issue = lambda pr: 1
    tal.issue_data = lambda n: {"labels": [tal.L_PASSED]}
    tal.pr_verdict_pass_evidence = lambda pr, sha=None, head_branch=None: ("abc123abc123", "9")
    tal.pr_checks = lambda pr: ("fail", ["arch-gate=fail", "web/admin=pass", "pest=skipping"])
    _, why_red, _ = tal.merge_blockers(42, require_ci=False)
    check(why_red == [tal.CI_RED + "arch-gate"],
          "blocker CI nêu ĐÚNG check đỏ (không bắt người dò `=fail` giữa cả bảng)",
          str(why_red))
    tal.pr_checks = lambda pr: ("pass", ["arch-gate=pass"])
    _, why_green, _ = tal.merge_blockers(42, require_ci=False)
    check(why_green == [], "CI xanh ⇒ không blocker nào", str(why_green))
    restore_tal()

    # ── CỬA THẬT, chiều ĐỎ: `--force` KHÔNG mở ────────────────────────────────
    for force in (False, True):
        calls: list = []
        _stub_merge_env([tal.CI_RED + "arch-gate"], calls)
        try:
            tal.cmd_merge(_merge_args(force=force, note="lý do gì đó"))
            check(False, f"CI đỏ + --force={force} ⇒ phải NÉM", "merge đi qua")
        except tal.Fail as e:
            msg = str(e)
            check("arch-gate" in msg and "--ci-red" in msg,
                  f"--force={force} ⇒ chặn, gọi TÊN check đỏ và chỉ đường đi tiếp", msg[:120])
        except Exception as e:                                   # noqa: BLE001
            check(False, f"--force={force} ⇒ chặn bằng Fail",
                  f"RÀO KHÔNG CHẶN — chảy tiếp rồi chết ở {type(e).__name__}: {e}")
        check(not any(c[:2] == ["pr", "merge"] for c in calls),
              f"--force={force} ⇒ KHÔNG có `gh pr merge` nào chạy", str(calls))
        restore_tal()

    # `--ci-red` trần (không --note) cũng bị chặn — cờ im lặng không phải dấu vết.
    calls = []
    _stub_merge_env([tal.CI_RED + "arch-gate"], calls)
    try:
        tal.cmd_merge(_merge_args(ci_red=True))
        check(False, "--ci-red không kèm --note ⇒ phải NÉM", "merge đi qua")
    except tal.Fail as e:
        check("--note" in str(e), "--ci-red trần ⇒ đòi --note", str(e)[:100])
    check(not any(c[:2] == ["pr", "merge"] for c in calls), "vẫn chưa merge gì", str(calls))
    restore_tal()

    # ── CHIỀU XANH ①: cùng đường đi, check CHUYỂN SANG PASS ⇒ merge chạy ──────
    # Nếu không có chiều này thì mọi khẳng định trên chỉ chứng minh `cmd_merge`
    # ném — chứ không chứng minh nó ném VÌ CI đỏ.
    calls = []
    _stub_merge_env([], calls)
    r = tal.cmd_merge(_merge_args())
    check(any(c[:2] == ["pr", "merge"] for c in calls),
          "check xanh ⇒ ĐI QUA cửa CI và merge thật", str(calls))
    check(r["pr"] == 42, "trả kết quả merge bình thường", str(r))
    restore_tal()

    # ── CHIỀU XANH ②: vẫn đỏ, nhưng `--ci-red --note` là lời khẳng định ý định ─
    calls = []
    _stub_merge_env([tal.CI_RED + "arch-gate"], calls)
    tal.cmd_merge(_merge_args(ci_red=True, note="đỏ do dev, không phải PR này"))
    check(any(c[:2] == ["pr", "merge"] for c in calls),
          "--ci-red --note ⇒ đi qua", str(calls))
    body = next((c[-1] for c in calls if c[:2] == ["pr", "comment"]), "")
    check("--ci-red" in body and "đỏ do dev" in body,
          "và việc bỏ qua CI đỏ được GHI LÊN PR, kèm lý do", body[:120])
    restore_tal()


def test_2639_base_red_blame_points_at_the_commit_that_broke_dev():
    print("chẩn đoán (#2639): base đỏ ⇒ chỉ ra commit + PR đã phá, và báo cho tác giả")

    # Hình dạng thật đo trên repo: `arch-gate` đỏ ở tip và ở commit ngay dưới,
    # xanh ở commit thứ ba ⇒ thủ phạm là cái ĐỎ CŨ NHẤT trong chuỗi liền.
    state = {"tip": "fail", "mid": "fail", "old": "ok"}
    posted: list = []
    api_calls: list = []
    tal._BASE_RED_SEEN.clear()      # nhớ theo tiến trình — không phải hàm, `restore_tal` không dọn

    def fake_api(args, default=None):
        api_calls.append(args[1])
        url = args[1]
        if "/commits?sha=" in url:
            return [{"sha": "tip"}, {"sha": "mid"}, {"sha": "old"}]
        if url.endswith("/check-runs?per_page=100"):
            sha = url.split("/commits/")[1].split("/")[0]
            concl = {"fail": "failure", "ok": "success"}.get(state.get(sha), None)
            return {"check_runs": [{"name": "arch-gate", "conclusion": concl}]}
        if url.endswith("/pulls"):
            return [{"number": 2630, "user": {"login": "ecsol"}, "merge_commit_sha": "mid"}]
        if "/comments?per_page=100" in url:
            return []
        return default

    tal.gh_json = lambda args, default=None: fake_api(args, default) if args[0] == "api" else default
    tal.gh = lambda args, check=True, stdin=None: posted.append(args)

    blame = tal.base_red_blame("arch-gate")
    check(blame == {"sha": "mid", "pr": 2630, "author": "ecsol"},
          "thủ phạm = commit ĐỎ cũ nhất trong chuỗi liền, kèm PR + tác giả", str(blame))

    hint = tal.base_red_hint(["arch-gate"])
    check("mid" in hint and "#2630" in hint and "ecsol" in hint,
          "dòng chỉ tay nêu commit, PR và người phá", hint)
    body = next((c[-1] for c in posted if c[:2] == ["pr", "comment"]), "")
    check("2630" in " ".join(str(x) for c in posted for x in c) and "arch-gate" in body,
          "và một comment được gửi LÊN PR đã phá — đó là thông báo cho người phá", body[:120])
    check(tal.BASE_RED_MARK in body, "comment mang marker idempotent", body[:80])

    # Gọi lần hai trong CÙNG tiến trình (hình dạng `merge-batch`: mọi PR trong lô
    # đỏ vì đúng một lý do) ⇒ không hỏi lại GitHub, không comment lại.
    n_api, n_posted = len(api_calls), len(posted)
    check(tal.base_red_hint(["arch-gate"]) == hint, "lần hai trả cùng câu")
    check(len(api_calls) == n_api and len(posted) == n_posted,
          "…mà KHÔNG gọi thêm API và KHÔNG comment lần nữa",
          f"api +{len(api_calls) - n_api}, comment +{len(posted) - n_posted}")

    # Chiều ngược: base XANH ⇒ không đổ cho ai, không comment cho ai.
    state["tip"] = "ok"
    posted.clear()
    tal._BASE_RED_SEEN.clear()
    check(tal.base_red_blame("arch-gate") is None,
          "base xanh ⇒ None (PR tự đỏ, không có ai để đổ)")
    check(tal.base_red_hint(["arch-gate"]) == "", "…và không có dòng chỉ tay")
    check(posted == [], "…và KHÔNG comment oan lên PR nào", str(posted))

    # Không đo được ⇒ im lặng đi tiếp, KHÔNG làm chết lệnh merge, và KHÔNG nhớ
    # kết quả rỗng đó (mạng hỏng một lần không được làm câm chẩn đoán mãi mãi).
    tal._BASE_RED_SEEN.clear()
    tal.gh_json = lambda args, default=None: (_ for _ in ()).throw(RuntimeError("mạng hỏng"))
    check(tal.base_red_hint(["arch-gate"]) == "",
          "gh hỏng ⇒ chẩn đoán nuốt lỗi, rào CI vẫn chặn như thường")
    check("arch-gate" not in tal._BASE_RED_SEEN, "…và lỗi tạm thời KHÔNG bị nhớ")
    tal._BASE_RED_SEEN.clear()


# ─────────────────────────────────────────────────────────────────────────────
# #2669 — CHƯA XONG cũng chặn, và `skipping` thì KHÔNG
# ─────────────────────────────────────────────────────────────────────────────
#
# Hình dạng dữ liệu dưới đây là ĐO THẬT (2026-08-13, gh 2.96.0), không phải bịa:
#   `gh pr checks 2663 -R godx-jp/godx-tempo --json name,bucket,state,startedAt`
#       arch-gate                pass/SUCCESS   17:54:43Z → 17:55:42Z
#       pest · timezone-matrix · flake-hunt      skipping/SKIPPED
#       build · vet · gofmt · test               pass/SUCCESS
#   PR đó merge lúc 17:55:03Z — tức 39 GIÂY TRƯỚC khi arch-gate xanh.
# Bucket `pending` cũng đo trên repo này, PR #2670 lúc 18:24:44Z (ngay sau push):
#   `build · vet · gofmt · test` · `arch-gate` · `pos-web api manifest …`
#       pending/IN_PROGRESS
#   `pest` · `timezone-matrix` · `flake-hunt`   skipping/SKIPPED
# và cùng PR đó lúc 18:28:54Z: pass ×3 + skipping ×3, không còn pending.

def _stub_pending_blockers_env(rows):
    """Đủ để `merge_blockers` THẬT chạy tới phần CI, với bảng check là `rows`."""
    tal.gh_json = lambda args, default=None: (
        rows if args[:2] == ["pr", "checks"] else
        {"isDraft": False, "mergeable": "MERGEABLE", "state": "OPEN",
         "headRefName": "issue-1", "headRefOid": "abc123abc123", "labels": []})
    tal.pr_issue = lambda pr: 1
    tal.issue_data = lambda n: {"labels": [tal.L_PASSED]}
    tal.pr_verdict_pass_evidence = lambda pr, sha=None, head_branch=None: ("abc123abc123", "9")


def test_2669_pending_ci_refuses_merge_and_skipping_is_not_pending():
    print("rào CI (#2669): check CHƯA XONG chặn merge; `skipping` KHÔNG chặn; "
          "`--force` không mở, chỉ `--ci-red --note`")

    # ── phần thuần tuý: từ vựng bucket ────────────────────────────────────────
    p = tal.pending_check_names
    check(p(["arch-gate=pending", "web/admin=pass", "pest=skipping"]) == ["arch-gate"],
          "chỉ lọc check CHƯA XONG — `skipping` là kết luận hợp lệ, không phải chưa xong")
    check(p(["pest=skipping", "timezone-matrix=skipping", "flake-hunt=skipping",
             "arch-gate=pass"]) == [],
          "bảng thật của PR #2663 (3 skipping + pass) ⇒ KHÔNG chặn gì cả")
    # Tên check của repo này CHỨA " · ", y như ca #2639.
    check(p(["build · vet · gofmt · test=pending"]) == ["build · vet · gofmt · test"],
          "KHÔNG cắt vụn tên check chứa ` · `")
    check(p(["arch-gate=pending", "arch-gate=pending"]) == ["arch-gate"],
          "check chạy lại để lại nhiều dòng cùng tên ⇒ gộp, không in `arch-gate · arch-gate`")
    check(p(["mystery=waiting_for_godot"]) == ["mystery"],
          "bucket LẠ rơi về phía CHƯA XONG (fail-closed: chặn nhầm thấy được, "
          "cho qua nhầm thì không)")
    check(p(["arch-gate=fail"]) == [], "check ĐỎ là việc của #2639, không lẫn vào đây")

    v = tal.ci_pending_verdict
    check(v([tal.CI_PENDING + "arch-gate"], False) == ["arch-gate"],
          "blocker CHƯA XONG ⇒ chặn, kèm mô tả")
    check(v([tal.CI_PENDING + "build · vet · gofmt · test"], False)
          == ["build · vet · gofmt · test"], "payload không bị cắt theo ` · `")
    check(v([tal.CI_PENDING + "arch-gate"], True) == [],
          "--ci-red ⇒ cho qua (một cờ cho cả hai sắc thái 'CI chưa nói')")
    check(v([tal.CI_RED + "arch-gate", "PR đang là draft"], False) == [],
          "blocker KHÁC (kể cả CI ĐỎ) không bị nhầm thành CHƯA XONG")

    a = tal.check_age_label
    base = tal.datetime.strptime("2026-08-12T17:54:43Z", "%Y-%m-%dT%H:%M:%SZ").replace(
        tzinfo=tal.timezone.utc).timestamp()
    check(a("2026-08-12T17:54:43Z", now=base + 45) == "45s", "dưới 90s ⇒ đếm giây")
    check(a("2026-08-12T17:54:43Z", now=base + 8 * 60 + 5) == "8m", "trên 90s ⇒ đếm phút")
    check(a(None) == "" and a("hôm qua") == "" and a("2026-08-12T17:54:43Z", now=base - 10) == "",
          "thiếu/hỏng/âm ⇒ chuỗi rỗng, KHÔNG làm chết lệnh merge")

    # ── `pr_checks` phân loại đúng trên hình dạng THẬT ────────────────────────
    tal.gh_json = lambda args, default=None: [
        {"name": "arch-gate", "bucket": "pending", "startedAt": "2026-08-12T17:54:43Z"},
        {"name": "build · vet · gofmt · test", "bucket": "pass", "startedAt": None},
        {"name": "pest", "bucket": "skipping", "startedAt": None}]
    check(tal.pr_checks(7)[0] == "pending", "một check đang chạy ⇒ tổng thể CHƯA XONG")
    tal.gh_json = lambda args, default=None: [
        {"name": "arch-gate", "bucket": "pass", "startedAt": None},
        {"name": "pest", "bucket": "skipping", "startedAt": None}]
    check(tal.pr_checks(7)[0] == "pass",
          "pass + skipping (bảng thật của #2663 sau khi xong) ⇒ XANH, không chặn oan")
    tal.gh_json = lambda args, default=None: [
        {"name": "arch-gate", "bucket": "fail", "startedAt": None},
        {"name": "pest", "bucket": "pending", "startedAt": None}]
    check(tal.pr_checks(7)[0] == "fail", "đỏ THẮNG chưa-xong — thông điệp #2639 vẫn đúng")
    restore_tal()

    # ── `merge_blockers`: chặn kể cả require_ci=False (đường MẶC ĐỊNH và đường LÔ) ─
    rows_pending = [
        {"name": "arch-gate", "bucket": "pending", "startedAt": "2026-08-12T17:54:43Z"},
        {"name": "build · vet · gofmt · test", "bucket": "pending", "startedAt": None},
        {"name": "pest", "bucket": "skipping", "startedAt": None},
        {"name": "web/admin", "bucket": "pass", "startedAt": None}]
    _stub_pending_blockers_env(rows_pending)
    _, why_pending, _ = tal.merge_blockers(42, require_ci=False)
    check(why_pending == [tal.CI_PENDING + "arch-gate · build · vet · gofmt · test"],
          "require_ci=False (mặc định của `tal merge` VÀ của merge-batch) vẫn chặn, "
          "và nêu ĐÚNG check còn chạy", str(why_pending))

    # …và CÙNG PR đó, khi check kia xong: không blocker nào.
    rows_done = [dict(r, bucket=("pass" if r["bucket"] == "pending" else r["bucket"]))
                 for r in rows_pending]
    _stub_pending_blockers_env(rows_done)
    _, why_done, _ = tal.merge_blockers(42, require_ci=False)
    check(why_done == [], "check xanh xong ⇒ hết blocker", str(why_done))

    # Chỉ toàn `skipping` bên cạnh `pass` ⇒ KHÔNG chặn (ca làm chết cả rào nếu sai).
    _stub_pending_blockers_env([
        {"name": "arch-gate", "bucket": "pass", "startedAt": None},
        {"name": "pest", "bucket": "skipping", "startedAt": None},
        {"name": "timezone-matrix", "bucket": "skipping", "startedAt": None},
        {"name": "flake-hunt", "bucket": "skipping", "startedAt": None}])
    _, why_skip, _ = tal.merge_blockers(42, require_ci=False)
    check(why_skip == [], "PR chỉ có skipping ngoài pass ⇒ ĐI QUA", str(why_skip))
    restore_tal()

    # ── CỬA THẬT, chiều CHƯA XONG: `--force` KHÔNG mở ─────────────────────────
    for force in (False, True):
        calls: list = []
        _stub_merge_env([tal.CI_PENDING + "arch-gate"], calls)
        tal.pr_check_rows = lambda pr: [
            {"name": "arch-gate", "bucket": "pending",
             "startedAt": tal.datetime.now(tal.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")}]
        try:
            tal.cmd_merge(_merge_args(force=force, note="lý do gì đó"))
            check(False, f"CI chưa xong + --force={force} ⇒ phải NÉM", "merge đi qua")
        except tal.Fail as e:
            msg = str(e)
            check("arch-gate" in msg and "đang chạy" in msg and "--ci-red" in msg,
                  f"--force={force} ⇒ chặn, gọi TÊN check còn chạy + đã chạy bao lâu",
                  msg[:160])
        except Exception as e:                                   # noqa: BLE001
            check(False, f"--force={force} ⇒ chặn bằng Fail",
                  f"RÀO KHÔNG CHẶN — chảy tiếp rồi chết ở {type(e).__name__}: {e}")
        check(not any(c[:2] == ["pr", "merge"] for c in calls),
              f"--force={force} ⇒ KHÔNG có `gh pr merge` nào chạy", str(calls))
        restore_tal()

    # `--ci-red` trần (không --note) cũng bị chặn.
    calls = []
    _stub_merge_env([tal.CI_PENDING + "arch-gate"], calls)
    tal.pr_check_rows = lambda pr: []
    try:
        tal.cmd_merge(_merge_args(ci_red=True))
        check(False, "--ci-red không kèm --note ⇒ phải NÉM", "merge đi qua")
    except tal.Fail as e:
        check("--note" in str(e), "--ci-red trần ⇒ đòi --note", str(e)[:100])
    check(not any(c[:2] == ["pr", "merge"] for c in calls), "vẫn chưa merge gì", str(calls))
    restore_tal()

    # ── CHIỀU XANH ①: CÙNG đường code, check đã xong ⇒ merge chạy thật ────────
    calls = []
    _stub_merge_env([], calls)
    r = tal.cmd_merge(_merge_args())
    check(any(c[:2] == ["pr", "merge"] for c in calls),
          "check xong ⇒ ĐI QUA cửa và merge thật", str(calls))
    check(r["pr"] == 42, "trả kết quả merge bình thường", str(r))
    restore_tal()

    # ── CHIỀU XANH ②: vẫn chưa xong, nhưng `--ci-red --note` là khẳng định ý định ─
    calls = []
    _stub_merge_env([tal.CI_PENDING + "arch-gate"], calls)
    tal.pr_check_rows = lambda pr: []
    tal.cmd_merge(_merge_args(ci_red=True, note="arch-gate kẹt 2 tiếng, runner GitHub sự cố"))
    check(any(c[:2] == ["pr", "merge"] for c in calls),
          "--ci-red --note ⇒ đi qua", str(calls))
    body = next((c[-1] for c in calls if c[:2] == ["pr", "comment"]), "")
    check("CHƯA XONG" in body and "runner GitHub sự cố" in body,
          "và việc merge khi CI chưa nói được GHI LÊN PR, kèm lý do — "
          "không dùng nhầm tiêu đề 'CI ĐỎ'", body[:160])
    restore_tal()


# ─────────────────────────────────────────────────────────────────────────────
# #2673 — PR mở NGOÀI vòng lặp: vô hình với CẢ HAI hàng đợi
# ─────────────────────────────────────────────────────────────────────────────

def test_review_queue_sees_a_pr_that_never_entered_the_loop():
    """PR #2662 nằm mở, mọi check xanh, `CLEAN`, 5h17 không sổ không nhãn — và chín lượt gọi
    `review-queue` liên tiếp báo "hàng đợi rỗng".

    Nó không có nhãn chờ-review vì `tal claim`/`tal pr` chưa từng chạy cho nó, và
    `queue` phía code thì lọc `agent:ready`. Hai hàng đợi lọc hai cái NHÃN, nên
    một PR không mang nhãn nào rơi ra khỏi cả hai — không phải "hết việc".
    """
    print("cmd_review_queue (#2673: rổ thứ ba cho PR không có sổ ledger)")
    import contextlib
    import io

    # #2760 — tác giả phải là một login trong `agentLogins`, nếu không PR rơi
    # sang rổ `humans` và bài này đo nhầm thứ. Ca #2673 mà nó dựng lại (PR #2662)
    # LÀ việc của agent mở ngoài vòng lặp, nên `ecsol` mới đúng ngữ cảnh; login
    # `t` cũ là chỗ giữ chỗ có từ trước khi rổ biết phân biệt người với agent.
    saved_logins = tal.AGENT_LOGINS
    tal.AGENT_LOGINS = {"ecsol"}

    prs = [
        # MỒ CÔI: branch đúng khuôn, không nhãn, không sổ, tác giả là AGENT.
        {"number": 2662, "title": "fix(release): semver tags", "headRefName": "issue-2660",
         "isDraft": False, "labels": [], "updatedAt": "2026-08-12T01:00:00Z",
         "author": {"login": "ecsol"}, "headRefOid": "aaa"},
        # KHÔNG mồ côi: cũng thiếu nhãn, nhưng CÓ sổ — đây là vòng sửa đang chạy
        # (hoặc nhãn vừa bị gỡ tay), một trạng thái khác hẳn.
        {"number": 2700, "title": "vòng sửa", "headRefName": "issue-2699",
         "isDraft": False, "labels": [], "updatedAt": "2026-08-12T02:00:00Z",
         "author": {"login": "t"}, "headRefOid": "bbb"},
        # Không phải branch của vòng lặp: không thuộc thẩm quyền hàng đợi này.
        {"number": 2701, "title": "dependabot", "headRefName": "dependabot/npm/x",
         "isDraft": False, "labels": [], "updatedAt": "2026-08-12T03:00:00Z",
         "author": {"login": "dependabot"}, "headRefOid": "ccc"},
    ]
    ledgers = {2660: ({}, None, None),
               2699: ({"issue": 2699, "history": ["worker session abcd1234 bàn giao PR #2700"]},
                      777, "now")}

    tal.gh_json_required = lambda args: prs
    tal.issue_data = lambda n: {"labels": []}
    tal.refs_all_full = lambda: []
    tal.ledger_read = lambda n: ledgers[n]
    tal.session_id = lambda: "deadbeefcafe"

    res = tal.cmd_review_queue(types.SimpleNamespace(json=True))

    # ── CHIỀU PHẢI KÊU ────────────────────────────────────────────────────────
    check([o["pr"] for o in res["orphans"]] == [2662],
          "PR không nhãn + không sổ vào rổ MỒ CÔI", str(res["orphans"]))
    check(res["orphans"][0]["issue"] == 2660 and res["orphans"][0]["author"] == "ecsol",
          "kèm issue + tác giả để người đọc biết đi hỏi ai", str(res["orphans"][0]))

    # ── CHIỀU PHẢI IM ─────────────────────────────────────────────────────────
    tal.AGENT_LOGINS = saved_logins

    check(all(o["pr"] != 2700 for o in res["orphans"]),
          "PR THIẾU NHÃN nhưng CÓ SỔ không bị gọi là mồ côi — gộp vào là kêu oan ở "
          "gần như mọi PR, và rào kêu oan thì bị tắt", str(res["orphans"]))
    check(all(o["pr"] != 2701 for o in res["orphans"]),
          "branch ngoài khuôn `issue-<số>` không thuộc rổ này", str(res["orphans"]))
    check(res["eligible"] == [] and res["claimed"] == [],
          "và không rơi nhầm vào hai rổ kia", str(res))

    buf = io.StringIO()
    with contextlib.redirect_stdout(buf):
        tal.cmd_review_queue(types.SimpleNamespace(json=False))
    text = buf.getvalue()
    check("rỗng THẬT" not in text,
          "bản chữ KHÔNG được nói 'rỗng THẬT' khi còn PR mồ côi — chính câu đó đã "
          "trấn an chín lượt liền", text)
    check("MỒ CÔI" in text and "2662" in text and "review-claim 2662" in text,
          "và nói được làm gì tiếp theo, bằng lệnh CHẠY ĐƯỢC "
          "(`adopt` đòi lease của chính mình nên vô dụng ở đây)", text)

    # ── "không đo được" KHÁC "không có sổ" (#2300) ────────────────────────────
    def ledger_blows_up(n):
        if n == 2660:
            raise RuntimeError("GraphQL: API rate limit already exceeded")
        return ledgers[n]

    tal.ledger_read = ledger_blows_up
    res = tal.cmd_review_queue(types.SimpleNamespace(json=True))
    check(res["orphans"] == [],
          "API hỏng một nhịp KHÔNG được đọc thành 'mồ côi' — đó là cảnh báo bịa",
          str(res["orphans"]))

    # ── rỗng THẬT vẫn phải nói được là rỗng ───────────────────────────────────
    tal.gh_json_required = lambda args: []
    buf = io.StringIO()
    with contextlib.redirect_stdout(buf):
        tal.cmd_review_queue(types.SimpleNamespace(json=False))
    check("rỗng THẬT" in buf.getvalue(),
          "không còn gì thật thì câu trấn an được phép in — rào phải biết IM",
          buf.getvalue())


# ─────────────────────────────────────────────────────────────────────────────
# #2674 — `gc` đo tổ tiên SHA trong một repo squash-merge ⇒ giữ lại 100%
# ─────────────────────────────────────────────────────────────────────────────

def _squash_merge_fixture(root: Path, land_on_base: bool, base_moves_on: bool = False):
    """Dựng repo thật: `origin` bare + cây làm việc + một nhánh `issue-N`.

    `land_on_base=True` mô phỏng **squash-merge**: nội dung y hệt được ghi lên
    base bằng một commit MỚI (SHA khác) — đúng cách repo này merge.
    Trả về đường dẫn cây đang đứng trên nhánh.
    """
    up = root / "up.git"
    _git("init", "-q", "--bare", "-b", "main", str(up))

    wt = root / "wt"
    _init_repo(wt, str(up))
    _git("-C", str(wt), "push", "-q", "-u", "origin", "main")

    _git("-C", str(wt), "checkout", "-q", "-b", "issue-2606")
    (wt / "feature.txt").write_text("nội dung của nhánh\n")
    _git("-C", str(wt), "add", "feature.txt")
    _git("-C", str(wt), "commit", "-q", "-m", "feat: thêm feature.txt")

    if land_on_base:
        base = root / "base"
        _git("clone", "-q", str(up), str(base))
        _git("-C", str(base), "config", "user.email", "tal-test@example.com")
        _git("-C", str(base), "config", "user.name", "tal test")
        _git("-C", str(base), "config", "commit.gpgsign", "false")
        (base / "feature.txt").write_text("nội dung của nhánh\n")
        _git("-C", str(base), "add", "feature.txt")
        # Commit MỚI, thông điệp khác, SHA khác — chính là hình dạng squash.
        _git("-C", str(base), "commit", "-q", "-m", "feat: thêm feature.txt (#2606) (squash)")
        if base_moves_on:
            # base ĐI TIẾP sau khi merge — đây là ca #2616/#2640 trong issue: nội
            # dung file khác đi, nhưng không có gì của nhánh bị mất.
            (base / "feature.txt").write_text("nội dung của nhánh\nrồi dev sửa tiếp\n")
            _git("-C", str(base), "add", "feature.txt")
            _git("-C", str(base), "commit", "-q", "-m", "chore: dev đi tiếp trên cùng file")
        _git("-C", str(base), "push", "-q", "origin", "main")

    _git("-C", str(wt), "fetch", "-q", "origin")
    return wt


def test_gc_keeps_a_worktree_by_content_not_by_sha_ancestry():
    """`git log origin/dev..HEAD` hỏi về TỔ TIÊN THEO SHA. Repo này squash-merge,
    nên commit của nhánh không bao giờ là tổ tiên của base — và vòng lặp gọi rào
    này chỉ duyệt PR **đã merge**, tức nó bắn trúng 100% worktree nó gặp.

    Sáu worktree tích lại theo đúng đường đó, mỗi cái mang nguyên một lượt
    `setup` (vendor/node_modules/.env).
    """
    print("gc: đo NỘI DUNG chưa tới base, không đo tổ tiên SHA (#2674)")

    # ── CHIỀU PHẢI IM: đã squash-merge, cây sạch ⇒ nhả worktree ───────────────
    with tempfile.TemporaryDirectory() as td:
        wt = _squash_merge_fixture(Path(td), land_on_base=True)

        # Phép đo CŨ trước đã — nếu nó cũng nói "sạch" thì fixture chưa tái hiện
        # được lỗi và cả bài test này là vô nghĩa.
        old = tal.run(["git", "log", "--oneline", "origin/main..HEAD"],
                      cwd=str(wt), check=False).stdout.strip()
        check(bool(old),
              "fixture ĐÚNG là ca lỗi: phép đo cũ vẫn thấy commit 'chưa tới base'", old)

        keep = tal.worktree_unmerged_content(wt, "main")
        check(keep is None,
              "phép đo mới: patch đã ở upstream ⇒ KHÔNG giữ, gc dọn được", str(keep))

    # ── CHIỀU PHẢI IM ②: đã merge RỒI base đi tiếp trên cùng file ─────────────
    # Đây là ca duy nhất mà TẦNG 1 (`git cherry`) là thứ cứu: so nội dung file sẽ
    # ra DIFF vì `dev` sửa tiếp, nên chỉ có tầng 2 thì worktree bị giữ mãi mãi.
    # #2616 (2 khoá i18n) và #2640 (4 `it(...)`) là đúng hình dạng này — đã kiểm
    # tay từng phần thêm vào, tất cả đều CÓ trên dev.
    with tempfile.TemporaryDirectory() as td:
        wt = _squash_merge_fixture(Path(td), land_on_base=True, base_moves_on=True)

        same = tal.run(["git", "diff", "--quiet", "origin/main", "HEAD", "--", "feature.txt"],
                       cwd=str(wt), check=False).returncode == 0
        check(not same,
              "fixture ĐÚNG là ca chỉ tầng 1 giải được: so nội dung file cho ra DIFF")

        keep = tal.worktree_unmerged_content(wt, "main")
        check(keep is None,
              "patch đã ở upstream (tầng 1) ⇒ nhả, dù base đã sửa tiếp cùng file", str(keep))

    # ── CHIỀU PHẢI IM ③: NHIỀU commit gộp thành MỘT ⇒ tầng 1 không cứu nổi ────
    # `git cherry` so patch-id, mà patch của hai commit rời KHÔNG bằng patch của
    # commit gộp — cả hai ra `+`. Chỉ so NỘI DUNG mới thấy base đã mang đủ. Đây
    # là hình dạng squash phổ biến nhất (PR nhiều commit), nên thiếu tầng 2 thì
    # rào lại giữ gần hết worktree y như cũ.
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        up = root / "up.git"
        _git("init", "-q", "--bare", "-b", "main", str(up))
        wt = root / "wt"
        _init_repo(wt, str(up))
        _git("-C", str(wt), "push", "-q", "-u", "origin", "main")
        _git("-C", str(wt), "checkout", "-q", "-b", "issue-2610")
        for i, line in enumerate(["một\n", "một\nhai\n"]):
            (wt / "feature.txt").write_text(line)
            _git("-C", str(wt), "add", "feature.txt")
            _git("-C", str(wt), "commit", "-q", "-m", f"feat: bước {i + 1}")

        base = root / "base"
        _git("clone", "-q", str(up), str(base))
        _git("-C", str(base), "config", "user.email", "tal-test@example.com")
        _git("-C", str(base), "config", "user.name", "tal test")
        _git("-C", str(base), "config", "commit.gpgsign", "false")
        (base / "feature.txt").write_text("một\nhai\n")     # kết quả GỘP của cả hai bước
        _git("-C", str(base), "add", "feature.txt")
        _git("-C", str(base), "commit", "-q", "-m", "feat: bước 1+2 (#2610) (squash)")
        _git("-C", str(base), "push", "-q", "origin", "main")
        _git("-C", str(wt), "fetch", "-q", "origin")

        cherry = tal.run(["git", "cherry", "origin/main", "HEAD"], cwd=str(wt), check=False).stdout
        check(cherry.count("+") == 2,
              "fixture ĐÚNG là ca chỉ tầng 2 giải được: cả hai commit vẫn ra `+`", cherry.strip())

        keep = tal.worktree_unmerged_content(wt, "main")
        check(keep is None,
              "nội dung đã có đủ trên base ⇒ nhả, dù patch-id không khớp", str(keep))

    # ── CHIỀU PHẢI IM ④: nhiều commit squash RỒI base đi tiếp (#2782/#2689) ──
    # Tầng 1 (cherry) ra `+` vì patch-id không khớp. Tầng 2 đối xứng (`diff
    # --quiet`) ra DIFF vì origin có dòng mới. Worktree không mang gì độc nhất
    # — added=0 — nên phải NHẢ. Đây là ca #2689: 9909 dòng "khác" theo chiều
    # worktree TỤT, không phải ôm việc riêng.
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        up = root / "up.git"
        _git("init", "-q", "--bare", "-b", "main", str(up))
        wt = root / "wt"
        _init_repo(wt, str(up))
        _git("-C", str(wt), "push", "-q", "-u", "origin", "main")
        _git("-C", str(wt), "checkout", "-q", "-b", "issue-2689")
        for i, line in enumerate(["một\n", "một\nhai\n"]):
            (wt / "feature.txt").write_text(line)
            _git("-C", str(wt), "add", "feature.txt")
            _git("-C", str(wt), "commit", "-q", "-m", f"feat: bước {i + 1}")

        base = root / "base"
        _git("clone", "-q", str(up), str(base))
        _git("-C", str(base), "config", "user.email", "tal-test@example.com")
        _git("-C", str(base), "config", "user.name", "tal test")
        _git("-C", str(base), "config", "commit.gpgsign", "false")
        (base / "feature.txt").write_text("một\nhai\n")
        _git("-C", str(base), "add", "feature.txt")
        _git("-C", str(base), "commit", "-q", "-m", "feat: bước 1+2 (#2689) (squash)")
        (base / "feature.txt").write_text("một\nhai\nrồi origin đi tiếp\n")
        _git("-C", str(base), "add", "feature.txt")
        _git("-C", str(base), "commit", "-q", "-m", "chore: origin đi tiếp")
        _git("-C", str(base), "push", "-q", "origin", "main")
        _git("-C", str(wt), "fetch", "-q", "origin")

        cherry = tal.run(["git", "cherry", "origin/main", "HEAD"], cwd=str(wt), check=False).stdout
        check("+" in cherry,
              "fixture ĐÚNG là ca tầng 1 không cứu nổi: cherry vẫn ra `+`", cherry.strip())
        same = tal.run(["git", "diff", "--quiet", "origin/main", "HEAD", "--", "feature.txt"],
                       cwd=str(wt), check=False).returncode == 0
        check(not same,
              "fixture ĐÚNG là ca lỗi đối xứng: so file ra DIFF vì origin mới hơn")

        keep = tal.worktree_unmerged_content(wt, "main")
        check(keep is None,
              "HEAD không mang dòng nào origin chưa có ⇒ nhả, dù so đối xứng ra DIFF",
              str(keep))

    # ── CHIỀU PHẢI KÊU: commit thật sự chưa từng tới base ⇒ giữ ───────────────
    with tempfile.TemporaryDirectory() as td:
        wt = _squash_merge_fixture(Path(td), land_on_base=False)

        keep = tal.worktree_unmerged_content(wt, "main")
        check(keep is not None,
              "commit chưa có ở đâu khác ⇒ PHẢI giữ. Thiếu chiều này thì bản sửa "
              "vô hiệu hoá #2300 A12 và `branch -D` nuốt mất bản sao cuối cùng")
        check("feature.txt" in (keep or ""),
              "và nói rõ mất gì nếu xoá", str(keep))

    # ── CHIỀU PHẢI KÊU ②: KHÔNG ĐO ĐƯỢC không phải là SẠCH (#2300) ────────────
    with tempfile.TemporaryDirectory() as td:
        wt = _squash_merge_fixture(Path(td), land_on_base=True)
        keep = tal.worktree_unmerged_content(wt, "nhanh-khong-ton-tai")
        check(keep is not None and "không so được" in keep,
              "origin/<base> không phân giải được ⇒ GIỮ, không im lặng dọn", str(keep))


# ─────────────────────────────────────────────────────────────────────────────
# #2682 — một tên `test_*` định nghĩa HAI LẦN thì bản đầu không bao giờ chạy
# ─────────────────────────────────────────────────────────────────────────────

def test_no_test_name_is_defined_twice():
    """18 hàm `test_2300_*` từng nằm trong file này HAI LẦN — một khối 501 dòng
    bị dán lặp.

    `discover_tests()` đọc `globals()`, mà `def` sau ghi đè `def` trước, nên 18
    khối đầu **không bao giờ chạy**. Hôm phát hiện thì 17 cặp giống hệt nhau và
    cặp thứ 18 chỉ khác một khối comment ở cuối — nên chưa ca nào âm thầm mất
    hiệu lực. Đó là MAY, không phải thiết kế: sửa nhầm bản trên rồi thấy suite
    xanh là tin mình đã canh, đúng họ #2202 (*"một bài test tồn tại, trông như
    đã canh, và không bao giờ nổ thì TỆ HƠN không có test"*).

    `MIN_TESTS` không bắt được: nó đếm tên DUY NHẤT (`globals()` đã gộp), nên
    dán trùng cả trăm hàm cũng không làm con số nhúc nhích.

    Đọc NGUỒN chứ không đọc `globals()` — đó là điểm của bài này: `globals()`
    chính là chỗ bản trùng biến mất.
    """
    print("tal_test.py: không tên `test_*` nào được định nghĩa hai lần (#2682)")

    # `Path(__file__)`, KHÔNG phải `HERE / "tal_test.py"`: ca #2202 chạy một BẢN
    # SAO của file này ở thư mục tạm với tên khác, nơi `HERE` trỏ vào thư mục tạm
    # và `tal_test.py` không tồn tại. Bản đầu viết theo `HERE` làm suite CON chết
    # bằng FileNotFoundError trước khi canary kịp chạy — tức bài #2202 đỏ vì lý do
    # sai, và bài này thì không đo gì cả ở đó.
    src = Path(__file__).read_text(encoding="utf-8")
    seen: dict[str, list[int]] = {}
    for m in re.finditer(r"^def (test_\w+)\(", src, re.M):
        seen.setdefault(m.group(1), []).append(src[: m.start()].count("\n") + 1)

    dupes = {name: lines for name, lines in seen.items() if len(lines) > 1}
    check(dupes == {},
          "mỗi tên `test_*` chỉ định nghĩa MỘT lần",
          "; ".join(f"{n} ở dòng {ls}" for n, ls in sorted(dupes.items())) or "sạch")

    # Và bài này chỉ có nghĩa khi nó thật sự nhìn thấy các hàm — một regex hỏng
    # cũng cho `dupes == {}`. Số tên đọc từ NGUỒN phải khớp số hàm `discover_tests()`
    # nhặt được từ `globals()`; lệch nghĩa là một trong hai phía đang mù.
    check(len(seen) == len(discover_tests()),
          "số hàm đọc từ nguồn khớp số hàm suite thật sự chạy",
          f"nguồn {len(seen)} · globals {len(discover_tests())}")


def test_agent_addr_says_live_or_dead():
    """#2704 — cột `addr` phải PHÂN BIỆT được socket sống với socket đã chết.

    Cả issue lẫn tin nhắn giữa hai phiên đều nêu cùng một rủi ro: in một đường
    dẫn đã chết y hệt một đường dẫn sống chỉ đổi kiểu mơ hồ này lấy kiểu mơ hồ
    khác — đúng họ với bẫy `session ?`, nơi lease sống và lease mồ côi in giống
    hệt nhau và người đọc không có cách nào biết.
    """
    tal = load_tal()

    with tempfile.TemporaryDirectory() as td:
        sock_dir = Path(td)
        tal._SOCK_DIR = sock_dir
        (sock_dir / "4242.sock").write_text("")

        check(tal.agent_addr({"agent_pid": 4242}) == f"uds:{sock_dir}/4242.sock",
              "socket còn ⇒ in địa chỉ gửi được",
              tal.agent_addr({"agent_pid": 4242}))

        dead = tal.agent_addr({"agent_pid": 9999})
        check("đã tắt" in dead and "uds:" not in dead,
              "socket mất ⇒ NÓI là đã tắt, không in đường dẫn chết",
              dead)

        # PID chỉ có nghĩa trên CÙNG máy. Một lease từ máy khác mang PID trùng số
        # với tiến trình ở đây sẽ cho ra địa chỉ trông sống mà gửi vào hư không.
        (sock_dir / "777.sock").write_text("")
        remote = tal.agent_addr({"agent_pid": 777, "host": "máy-khác"})
        check("uds:" not in remote and "máy-khác" in remote,
              "lease máy khác ⇒ KHÔNG khẳng định sống dù PID trùng socket ở đây",
              remote)

        check(tal.agent_addr({}) == "—",
              "lease cũ không có agent_pid ⇒ trống, không nổ")


def test_agent_pid_is_not_the_tal_process():
    """#2704 — `agent_pid()` phải trả PID của AGENT, không phải của `tal`.

    Field `pid` sẵn có là `os.getpid()` của chính tiến trình python `tal` — sống
    vài giây rồi chết, nên nó không bao giờ khớp một socket. Đó chính là lý do
    cầu nối digest→địa chỉ chưa từng dùng được dù dữ liệu gần như đã có.
    """
    tal = load_tal()
    pid = tal.agent_pid()

    # Trong CI không có tiến trình `claude`/`codex` cha ⇒ None là kết quả ĐÚNG.
    # Bài kiểm được ở mọi môi trường là: nó KHÔNG bao giờ trả PID của chính mình.
    check(pid != os.getpid(),
          "agent_pid() không trả PID của chính tiến trình tal",
          f"agent_pid={pid} · os.getpid()={os.getpid()}")
    check(pid is None or isinstance(pid, int),
          "agent_pid() trả int hoặc None, không trả kiểu khác",
          repr(pid))


def test_status_table_carries_addr_column():
    """#2704 — cột phải có TRONG bảng, không chỉ có hàm tính nó.

    Một helper đúng mà không ai in ra thì người bị chặn vẫn phải đi rải tin —
    tức vẫn đúng nguyên vấn đề ban đầu.
    """
    src = (HERE / "tal").read_text(encoding="utf-8")

    check("'addr':<30" in src,
          "header của `tal status` có cột addr")
    check("r.get('addr'" in src,
          "dòng in của `tal status` đọc addr từ mỗi hàng")
    check(src.count('"agent_pid": agent_pid()') == 2,
          "cả lease issue LẪN lease review đều ghi agent_pid",
          f"đếm được {src.count(chr(34)+'agent_pid'+chr(34)+': agent_pid()')}")



def test_orphan_bucket_excludes_human_prs():
    print("rổ `orphans` chỉ nhận PR của AGENT — PR của NGƯỜI không bị mời nhặt (#2760)")

    # Rổ `orphans` đo "nhánh issue-<N> + KHÔNG có sổ ledger" rồi kết luận "việc
    # agent bị bỏ rơi". Người mở PR cũng không có sổ — đo được ở repo này:
    # `lamtailoi2` và `Shu1237` dùng đúng quy ước nhánh đó. Rổ này được đọc là
    # lời mời nhặt việc, nên xếp nhầm = mời một session ghi verdict tự động,
    # hoặc claim đè, lên việc người ta đang làm.
    saved = tal.AGENT_LOGINS

    try:
        tal.AGENT_LOGINS = {"ecsol"}

        # Chiều DƯƠNG — rào phải KÊU cho PR của agent.
        check(tal.pr_is_agent_authored("ecsol") is True,
              "PR của tài khoản agent ⇒ mồ côi thật, vào rổ `orphans`")
        check(tal.pr_is_agent_authored("ECSOL") is True,
              "so tên KHÔNG phân biệt hoa thường — GitHub login không phân biệt")

        # Chiều ÂM — rào phải IM cho PR của người. Đây là vế mà #2760 sinh ra để
        # canh, và là vế một rào hay thiếu.
        check(tal.pr_is_agent_authored("lamtailoi2") is False,
              "PR của NGƯỜI ⇒ KHÔNG vào `orphans` (ca thật: PR #2759 nhánh issue-2757)")
        check(tal.pr_is_agent_authored("Shu1237") is False,
              "người thứ hai cũng vậy — repo có ≥2 người dùng chung quy ước nhánh")
        check(tal.pr_is_agent_authored(None) is False,
              "tác giả thiếu (API trả None) ⇒ KHÔNG đoán là agent; đoán sai chiều này "
              "mới là chiều gây hại")

        # Chưa khai config ⇒ giữ nguyên hành vi trước #2760, không tự dưng đổi.
        tal.AGENT_LOGINS = set()
        check(tal.pr_is_agent_authored("lamtailoi2") is True,
              "`agentLogins` rỗng ⇒ không lọc theo tác giả (repo chưa khai vẫn chạy như cũ)")
    finally:
        tal.AGENT_LOGINS = saved


def test_review_queue_reports_humans_separately():
    print("`review-queue` in rổ `humans` riêng và KHÔNG tính nó vào 'rỗng THẬT' (#2760)")

    src = (HERE / "tal").read_text()
    blk = src[src.index("def cmd_review_queue("):]
    blk = blk[:blk.index("\ndef ", 10)]

    check('"humans": humans' in blk,
          "JSON có khoá `humans` — người gọi máy đọc được, không phải chỉ in ra")
    check("orphans if pr_is_agent_authored(author) else humans" in blk,
          "phân rổ đi qua hàm thuần đã test hai chiều, không so chuỗi tại chỗ")
    check("not out and not claimed and not orphans" in blk
          and "not humans" not in blk.split("rỗng THẬT")[0][-200:],
          "`humans` KHÔNG chặn câu 'rỗng THẬT' — PR của người không phải việc của "
          "vòng lặp, còn nó mà báo hết việc là ĐÚNG")


def test_agent_logins_config_is_normalized_before_compare():
    print("`agentLogins` chuẩn hoá lúc ĐỌC CONFIG, không chỉ lúc so (#2762)")

    # Bài này canh đúng chỗ hai bài #2760 ở trên bỏ trắng: cả hai đều gán thẳng
    # `tal.AGENT_LOGINS = {...}`, tức kiểm phép SO SÁNH và không bao giờ chạy
    # phép PHÂN GIẢI config. Lỗi #2762 sống trọn trong khoảng trống đó.
    n = tal.normalize_agent_logins

    check(n([" ecsol "]) == {"ecsol"},
          "dấu cách thừa trong agent-loop.json bị cắt — đây LÀ lỗi #2762")
    check(n(["ECSOL", "\tecsol\n"]) == {"ecsol"},
          "hoa/thường + tab/newline gộp về một mục; không đẻ ra hai mục lệch nhau")
    check(n(["  ", "", None]) == set(),
          "mục rỗng / chỉ-khoảng-trắng / null bị bỏ — `None` KHÔNG được hoá thành "
          "login `\"none\"` (str(None) làm đúng thế nếu không chặn)")
    check(n(None) == set() and n([]) == set(),
          "chưa khai ⇒ tập rỗng ⇒ KHÔNG lọc theo tác giả (hành vi trước #2760)")

    # Vòng khép kín: phân giải rồi so, đúng đường mà `cmd_review_queue` đi.
    saved = tal.AGENT_LOGINS
    try:
        tal.AGENT_LOGINS = n([" ecsol "])
        check(tal.pr_is_agent_authored("ecsol") is True,
              "config có khoảng trắng thừa vẫn xếp PR agent vào `orphans` — trước "
              "khi sửa, nó rơi sang `humans` và IM LẶNG mãi mãi")
        check(tal.pr_is_agent_authored("lamtailoi2") is False,
              "và chiều ÂM không bị hỏng theo: PR của người vẫn ở ngoài `orphans`")
    finally:
        tal.AGENT_LOGINS = saved


def test_config_prints_agent_logins():
    print("`tal config` in `agentLogins` — khoá được ĐỌC thì phải NHÌN THẤY (#2348)")

    # #2348: tám khoá từng được khai mà không khoá nào được đọc, và không có cách
    # nào nhìn ra ngoài việc đọc mã `tal`. Chiều ngược cũng phải chặn: một khoá
    # ĐƯỢC đọc nhưng không in ra thì sai chính tả trong config vẫn vô hình.
    src = (HERE / "tal").read_text()
    blk = src[src.index("def cmd_config("):]
    blk = blk[:blk.index("\ndef ", 10)]

    check('"agentLogins": sorted(AGENT_LOGINS)' in blk,
          "nhánh --json có khoá `agentLogins` (giá trị ĐÃ phân giải, không phải raw)")
    check("agentLogins (" in blk,
          "nhánh in cho người cũng có, kèm số lượng")
    chunks = blk.split("agentLogins (")
    check(len(chunks) > 1 and "chưa khai" in chunks[1][:400],
          "rỗng thì nói rõ hệ quả, đừng in một dòng trống để người đọc tự đoán "
          "(dùng [1:2]/len chứ không [1] — khối biến mất phải FAIL sạch, không IndexError)")



def test_open_pr_closing_an_issue_pulls_it_out_of_the_queue():
    print("issue đã có PR MỞ khai `Closes` thì rời hàng đợi — kể cả PR cụm (#2769)")

    R = tal.L_READY

    # Chiều DƯƠNG: bản sửa đã tồn tại ⇒ đừng mời ai làm lại.
    why = tal.queue_skip_reason({R}, has_lease=False, closing_pr=2767)
    check(why is not None and "2767" in why,
          "nêu ĐÍCH DANH số PR — 'đã có người làm' mà không nói ở đâu thì người "
          "đọc vẫn phải đi tìm")

    # Chiều ÂM, và đây là vế dễ mất: không PR nào khai thì mọi thứ như cũ.
    check(tal.queue_skip_reason({R}, has_lease=False, closing_pr=None) is None,
          "không có PR nào đóng ⇒ vẫn nhặt được (bản vá không được bóp hàng đợi)")

    # Tham số có DEFAULT: mọi chỗ gọi cũ không truyền vẫn chạy nguyên hành vi.
    check(tal.queue_skip_reason({R}, has_lease=False) is None,
          "chữ ký cũ (2 tham số) giữ nguyên nghĩa")

    # `shipped` = đã merge, dứt khoát hơn một PR đang mở — nó phải thắng.
    why = tal.queue_skip_reason({R, tal.L_SHIPPED}, has_lease=False, closing_pr=999)
    check(why is not None and "ship" in why.lower(),
          "đã merge thì lý do là 'đã ship', không phải 'có PR mở'")

    # VÒNG REWORK — vế này thiếu ở bản đầu và nó tự đào một hố sâu hơn cái nó
    # lấp. Rework LUÔN có PR mở (`tal pr` chèn `Closes #N`, push lại cùng nhánh
    # nên PR không đóng), nên không trừ `changes-requested` ra thì issue vừa bị
    # trả về bị chôn với đúng câu "bản sửa đã có" — trong khi review vừa phán
    # bản sửa CHƯA đạt. Và nó TỰ DUY TRÌ: không ai nhặt ⇒ PR mở mãi ⇒ chôn mãi.
    check(tal.queue_skip_reason({R, tal.L_CHANGES}, has_lease=False, closing_pr=2767) is None,
          "`changes-requested` + PR mở ⇒ VẪN nhặt được; đây là ưu tiên CAO NHẤT "
          "của hàng đợi, chôn nó là đảo ngược đúng thứ tự hàng đợi sinh ra để giữ")
    check(tal.queue_skip_reason({R, tal.L_CHANGES}, has_lease=True, closing_pr=2767) is not None,
          "…nhưng lease sống vẫn thắng — ngoại lệ này chỉ gỡ MỘT lý do bỏ qua, "
          "không mở cửa cho hai session cùng nhặt")


def test_issues_closed_by_reads_only_closing_keywords():
    print("`issues_closed_by` bắt từ khoá ĐÓNG, bỏ qua `Refs` (#2769)")

    f = tal.issues_closed_by

    check(f("Closes #2745\nCloses #2754\nCloses #2739") == {2745, 2754, 2739},
          "PR cụm khai ba issue ⇒ bắt cả ba (đây LÀ ca đẻ ra #2769)")
    check(f("fixes #12") == {12} and f("RESOLVED #13") == {13},
          "mọi từ khoá đóng của GitHub, không phân biệt hoa thường")

    # `Refs` CỐ Ý không tính: `with_issue_ref()` chấp nhận nó cho PR làm một
    # phần, và một PR làm dở thì issue vẫn còn việc — rút nó ra là chôn việc.
    check(f("Refs #14") == set() and f("ref #15") == set(),
          "`Refs #N` = có chạm, chưa xong ⇒ issue Ở LẠI hàng đợi")

    # `#420` vs `#42` KHÔNG đo được gì: `\d+` tham lam nên nó nuốt cả `420` dù
    # có `\b` hay không (đo rồi — gỡ `\b` mà bài vẫn xanh). Thứ `\b` thật sự
    # chặn là mã dính chữ.
    check(f("Closes #42abc") == set(),
          "`#42abc` không phải số issue — thiếu `\\b` thì nó thành #42, đóng oan "
          "một issue không liên quan")
    check(f("Closes #420") == {420},
          "số nhiều chữ số vẫn nguyên vẹn")
    check(f(None) == set() and f("") == set(),
          "thân PR rỗng/None ⇒ không khai gì, không nổ")
    check(f("nói về closes #7 giữa câu") == {7},
          "từ khoá giữa câu vẫn tính — GitHub cũng vậy")



def test_claimed_map_only_looks_at_OPEN_prs():
    print("`issues_claimed_by_open_prs` chỉ hỏi PR MỞ — PR bị bỏ không chôn issue (#2769)")

    seen = {}

    def fake_required(args):
        seen["args"] = args
        return [
            {"number": 2767, "body": "Closes #2745\nCloses #2754\nCloses #2739"},
            {"number": 2759, "body": "Refs #2757 — làm một phần"},
            {"number": 2764, "body": None},
        ]

    try:
        tal.gh_json_required = fake_required
        m = tal.issues_claimed_by_open_prs()

        check(m == {2745: 2767, 2754: 2767, 2739: 2767},
              "PR cụm rút cả ba issue ra; `Refs` không rút gì; body None không nổ")

        # Vế ÂM của #2769 nằm ở ĐÂY, trong tham số truy vấn: một PR đã đóng mà
        # không merge phải trả issue về hàng đợi. Cách duy nhất đảm bảo điều đó
        # là không bao giờ hỏi tới nó. Nếu ai đổi sang `--state all` thì issue
        # của một PR bị bỏ sẽ biến mất khỏi hàng đợi VĨNH VIỄN, im lặng.
        # `.get`, không phải `[...]`: nếu ai đó đổi sang `gh_json` thì `fake_required`
        # không chạy và `seen` rỗng — phải ra một CÂU, không ra KeyError. Một
        # traceback vẫn là đỏ, nhưng nó không nói được đỏ VÌ CÁI GÌ.
        args = seen.get("args", [])
        check("--state" in args and args[args.index("--state") + 1] == "open",
              "truy vấn ghim `--state open`; `all`/`closed` sẽ chôn issue của PR bị bỏ")

        # #2151: nuốt lỗi ở đây dựng lại đúng cái bug đang sửa. Phải khẳng định
        # THẲNG là truy vấn đã chạy qua `gh_json_required`; để KeyError làm bằng
        # chứng thì lượt kiểm đột biến ra một traceback chứ không ra một câu.
        check("args" in seen,
              "truy vấn đi qua `gh_json_required` — `gh_json` nuốt lỗi thành map "
              "rỗng, tức mọi issue lại `eligible` mà không ai biết (#2151)")
    finally:
        tal.gh_json_required = REAL["gh_json_required"]



def test_cmd_queue_actually_wires_the_pr_check_in():
    print("`cmd_queue` NỐI map PR vào `queue_skip_reason` — không chỉ có sẵn hàm (#2769)")

    # Bài này sinh ra từ chính lượt kiểm đột biến: gỡ đối số `closing_pr` khỏi
    # chỗ gọi trong `cmd_queue` mà CẢ 114 bài vẫn xanh. Hai mảnh được test rời
    # nhau (hàm thuần + hàm gọi mạng) không chứng minh gì về chỗ chúng gặp nhau —
    # mà chỗ gặp nhau mới là thứ hàng đợi thật chạy qua.
    class A:
        json, verbose, limit = True, False, 50

    try:
        tal.refs_all = lambda: []
        tal.open_issues = lambda: [
            {"number": 2754, "title": "đã có PR", "labels": [{"name": tal.L_READY}], "body": ""},
            {"number": 2739, "title": "cùng PR cụm", "labels": [{"name": tal.L_READY}], "body": ""},
            {"number": 2900, "title": "chưa ai làm", "labels": [{"name": tal.L_READY}], "body": ""},
        ]
        # Hai issue từ CÙNG một PR cụm — đúng hình dạng đã đẻ ra #2769. Một
        # phần tử chỉ chứng minh dây nối; hai mới chứng minh nó lọc theo map.
        tal.issues_claimed_by_open_prs = lambda: {2754: 2767, 2739: 2767}
        tal.emit = lambda out, ok: out

        out = tal.cmd_queue(A())
        nums = [e["number"] for e in out["eligible"]]

        check(nums == [2900],
              "CẢ HAI issue của PR cụm bị loại; issue chưa ai làm vẫn còn — gỡ "
              "đối số `closing_pr` khỏi cmd_queue thì bài này ĐỎ")
    finally:
        for k in ("refs_all", "open_issues", "issues_claimed_by_open_prs", "emit"):
            setattr(tal, k, REAL[k])


def test_2768_scalar_agent_logins_is_not_iterated_as_characters():
    print("`normalize_agent_logins` bọc scalar string — không iterate từng ký tự (#2768)")
    n = tal.normalize_agent_logins
    check(n("ecsol") == {"ecsol"},
          'config `"agentLogins": "ecsol"` (thiếu []) vẫn ra {"ecsol"}, không phải '
          "từng ký tự")
    check(n("ECSOL") == {"ecsol"}, "scalar cũng được lower+strip")
    saved = tal.AGENT_LOGINS
    try:
        tal.AGENT_LOGINS = n("ecsol")
        check(tal.pr_is_agent_authored("ecsol") is True,
              "PR agent không rơi sang humans khi config thiếu []")
        check(tal.pr_is_agent_authored("lamtailoi2") is False,
              "chiều âm: PR của người vẫn ngoài orphans")
    finally:
        tal.AGENT_LOGINS = saved
    # list vẫn như cũ — 111 ca hiện tại
    check(n(["ecsol", "other"]) == {"ecsol", "other"},
          "list không đổi hành vi")


def test_2782_gc_fetches_origin_base_before_measuring():
    print("cmd_gc fetch origin/<base> TRƯỚC khi đo unmerged (#2782)")
    src = (HERE / "tal").read_text()
    blk = src[src.index("def cmd_gc("):]
    blk = blk[:blk.index("\ndef ", 10)]
    fetch = 'run(["git", "fetch", "-q", "origin", BASE_BRANCH]'
    check(fetch in blk, "cmd_gc fetch origin/<base> trước khi đo")
    check(blk.index(fetch) < blk.index("worktree_unmerged_content"),
          "fetch đứng TRƯỚC phép đo unmerged — gỡ dòng fetch thì bài này ĐỎ")
    check("worktree_paths_for_issue" in blk,
          "gc hỏi git worktree list, không đoán `.claude/worktrees/issue-N` (#2710)")


def test_2710_remove_worktree_does_not_claim_success_when_branch_remains():
    print("remove_worktree: branch còn thì KHÔNG khai đã xoá (#2710)")
    src = (HERE / "tal").read_text()
    blk = src[src.index("def remove_worktree("):]
    blk = blk[:blk.index("\ndef ", 10)]
    check("KHÔNG khai đã xoá" in blk,
          "thất bại xoá branch/worktree phải nói ra, không nuốt stderr")
    check("worktree_paths_for_issue" in blk,
          "xoá theo đường dẫn git worktree list, không chỉ `.claude/worktrees/`")
    check('["git", "branch", "-D"' in blk,
          "xoá branch bằng -D (không -d so HEAD cây chính)")


def test_2782_gc_keeps_delete_only_work_but_still_releases_landed_deletes():
    """#2782 vòng 2 — commit CHỈ XOÁ chấm `added==0` trên numstat, nên tầng 2
    từng gọi nó là "không có gì để mất": gc nhả worktree rồi `branch -D` nuốt
    bản sao cuối cùng của một commit xoá mã — đúng hình dạng chiến dịch #2188
    (xoá legacy thuần). Đảo ngược chính bất biến của hàm: mọi đường lỗi lệch
    về GIỮ, riêng patch chỉ-xoá thì bị vứt.

    Chiều ngược phải giữ nguyên: bản xoá ĐÃ squash lên base thì vẫn nhả —
    đánh dấu bừa mọi `deleted>0` là unique thì gc quay lại không dọn được gì
    (#2674/#2689).
    """
    print("gc: xoá-dòng chưa merge ⇒ GIỮ; xoá đã landed ⇒ vẫn nhả (#2782 vòng 2)")

    # ── PHẢI KÊU: `git rm` + gỡ dòng, chưa từng tới base ─────────────────────
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        up = root / "up.git"
        _git("init", "-q", "--bare", "-b", "main", str(up))
        wt = root / "wt"
        _init_repo(wt, str(up))
        (wt / "dead.txt").write_text("hai\ndòng\n")
        (wt / "lines.txt").write_text("một\nhai\nba\n")
        _git("-C", str(wt), "add", "dead.txt", "lines.txt")
        _git("-C", str(wt), "commit", "-q", "-m", "seed")
        _git("-C", str(wt), "push", "-q", "-u", "origin", "main")
        _git("-C", str(wt), "checkout", "-q", "-b", "issue-2188")
        _git("-C", str(wt), "rm", "-q", "dead.txt")
        _git("-C", str(wt), "commit", "-q", "-m", "chore: gỡ file chết (#2188)")
        (wt / "lines.txt").write_text("một\nba\n")
        _git("-C", str(wt), "add", "lines.txt")
        _git("-C", str(wt), "commit", "-q", "-m", "chore: gỡ dòng chết (#2188)")
        _git("-C", str(wt), "fetch", "-q", "origin")

        # fixture ĐÚNG là ca lỗi: cả hai file chấm added==0 — phép đo cũ vứt chúng
        for f in ("dead.txt", "lines.txt"):
            ns = tal.run(["git", "diff", "--numstat", "origin/main", "HEAD", "--", f],
                         cwd=str(wt), check=False).stdout.strip()
            check(ns.startswith("0\t"),
                  f"fixture: {f} chấm added==0 trên numstat", ns)

        keep = tal.worktree_unmerged_content(wt, "main")
        check(keep is not None,
              "commit chỉ-xoá chưa tới base ⇒ PHẢI giữ — nhả là `branch -D` nuốt "
              "bản sao cuối cùng của bản xoá (#2188 đẻ ra đúng loại commit này)")
        check("dead.txt" in (keep or "") or "lines.txt" in (keep or ""),
              "và nói rõ file nào đang mang bản xoá", str(keep))

    # ── PHẢI IM: bản xoá đã squash lên base (2 commit → 1, cherry ra `+`) ─────
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        up = root / "up.git"
        _git("init", "-q", "--bare", "-b", "main", str(up))
        wt = root / "wt"
        _init_repo(wt, str(up))
        (wt / "dead.txt").write_text("hai\ndòng\n")
        (wt / "lines.txt").write_text("một\nhai\nba\n")
        _git("-C", str(wt), "add", "dead.txt", "lines.txt")
        _git("-C", str(wt), "commit", "-q", "-m", "seed")
        _git("-C", str(wt), "push", "-q", "-u", "origin", "main")
        _git("-C", str(wt), "checkout", "-q", "-b", "issue-2188")
        _git("-C", str(wt), "rm", "-q", "dead.txt")
        _git("-C", str(wt), "commit", "-q", "-m", "chore: gỡ file chết")
        (wt / "lines.txt").write_text("một\nba\n")
        _git("-C", str(wt), "add", "lines.txt")
        _git("-C", str(wt), "commit", "-q", "-m", "chore: gỡ dòng chết")

        base = root / "base"
        _git("clone", "-q", str(up), str(base))
        _git("-C", str(base), "config", "user.email", "tal-test@example.com")
        _git("-C", str(base), "config", "user.name", "tal test")
        _git("-C", str(base), "config", "commit.gpgsign", "false")
        _git("-C", str(base), "rm", "-q", "dead.txt")           # kết quả GỘP của cả
        (base / "lines.txt").write_text("một\nba\n")            # hai commit xoá
        _git("-C", str(base), "add", "lines.txt")
        _git("-C", str(base), "commit", "-q", "-m", "chore: gỡ chết (#2188) (squash)")
        _git("-C", str(base), "push", "-q", "origin", "main")
        _git("-C", str(wt), "fetch", "-q", "origin")

        cherry = tal.run(["git", "cherry", "origin/main", "HEAD"],
                         cwd=str(wt), check=False).stdout
        check(cherry.count("+") == 2,
              "fixture ĐÚNG là ca tầng 1 không cứu nổi: cả hai commit vẫn ra `+`",
              cherry.strip())

        keep = tal.worktree_unmerged_content(wt, "main")
        check(keep is None,
              "bản xoá đã có đủ trên base ⇒ nhả — đánh dấu bừa deleted>0 là unique "
              "thì gc không dọn được gì (chiều #2674)", str(keep))


def test_2782_remove_worktree_refuses_to_destroy_the_main_worktree():
    """#2782 vòng 2 — branch `issue-N` checkout ở CÂY CHÍNH (hook ép mọi branch
    mang tên đó, làm việc thẳng trong umbrella là chuyện có thật) ⇒
    `worktree_paths_for_issue` (#2710, hỏi `git worktree list`) liệt kê chính
    cây chính. `git worktree remove --force` từ chối ("is a main working tree"),
    nhưng orphan-fallback (#2177) không hỏi lại: rename cả repo thành
    `<tên>.orphan-<ts>` rồi rmtree — mất repo, mọi branch cục bộ, sổ worktree.

    Fixture git THẬT trong thư mục tạm — đây đúng là ca không bao giờ được
    probe trên repo thật.
    """
    print("remove_worktree: cây chính lọt danh sách ⇒ từ chối, không rename/rmtree (#2782 vòng 2)")

    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        up = root / "up.git"
        _git("init", "-q", "--bare", "-b", "main", str(up))
        repo = root / "mainrepo"
        _init_repo(repo, str(up))
        _git("-C", str(repo), "checkout", "-q", "-b", "issue-88")

        class FakeCtx:
            main_worktree = repo
            worktrees_dir = repo / ".claude" / "worktrees"
        tal.C = FakeCtx()

        # fixture ĐÚNG là ca lỗi: git worktree list đưa CÂY CHÍNH vào danh sách
        paths = tal.worktree_paths_for_issue(88)
        check(any(p.resolve() == repo.resolve() for p in paths),
              "fixture: worktree_paths_for_issue nhìn thấy chính cây chính", str(paths))

        try:
            ok = tal.remove_worktree(88)
        except Exception as e:
            # Bản chưa vá chết ĐÚNG kiểu này: repo bị rename+rmtree dưới chân nó
            # rồi FileNotFoundError — quy về FAIL sạch, đừng giết cả suite.
            ok = e
        check(ok is False, "trả False — KHÔNG BAO GIỜ khai đã dọn cây chính", repr(ok))
        check(repo.exists() and (repo / ".git").exists(),
              "repo + .git còn nguyên — không bị rename/rmtree")
        shells = list(root.glob("*.orphan-*"))
        check(shells == [],
              "không có vỏ `.orphan-*` nào — orphan-fallback không được chạy",
              str(shells))
        if repo.exists():
            br = tal.run(["git", "rev-parse", "--verify", "-q", "refs/heads/issue-88"],
                         cwd=str(repo), check=False)
            check(br.returncode == 0, "branch issue-88 còn sống — không bị `-D`")
        else:
            check(False, "branch issue-88 còn sống — không bị `-D`",
                  "cả repo đã biến mất — không còn gì để hỏi")


def test_2788_reap_with_open_pr_goes_to_review_not_queued():
    """Reap khi PR đã mở phải stamp awaiting-review, KHÔNG queued (#2788)."""
    print("reap: PR mở ⇒ review + awaiting-review, không queued (#2788)")
    from datetime import timedelta
    labelled = []
    written = []
    notes = []
    past = tal.now() - timedelta(hours=3)
    led = {"issue": 2784, "group": [2784], "state": "executing", "reaps": 0,
           "lease": {"session": "dead", "ttl": 2700}, "pr": None, "history": []}
    tal.refs_all = lambda: ["issue-2784"]
    tal.parent_of = lambda n, body=None: None
    tal.ledger_read = lambda n: (led, 1, past)
    tal.ledger_write = lambda d, cid, note=None: (written.append(json.loads(json.dumps(d))),
                                                   notes.append(note), 1)[2]
    tal.set_state_labels = lambda n, desired, drop=None, preserve=None: labelled.append(
        (n, set(desired), preserve))
    tal.ref_delete = lambda k: None
    tal.local_unlock = lambda k: None
    tal.orphan_seen_clear = lambda k: None
    tal.open_pr_for_issue_branch = lambda n: {"number": 2785, "state": "OPEN"}

    tal.reap_leases(dry=False)
    check(written and written[0]["state"] == "review",
          "có PR mở ⇒ state=review (queued là ĐỎ)", str(written[:1]))
    check(written and written[0].get("pr") == 2785, "ghi số PR vào sổ")
    want = labelled[0][1] if labelled else set()
    check(tal.L_AWAIT in want and tal.L_REVIEWING in want,
          "gắn awaiting-review + reviewing", str(labelled))
    check(tal.L_READY not in want,
          "KHÔNG gắn ready — không về hàng đợi code", str(labelled))


def test_2788_reap_without_pr_reattaches_ready():
    """Không PR ⇒ gắn lại agent:ready. desired rỗng là đúng bug cũ (#2788)."""
    print("reap: không PR ⇒ gắn lại agent:ready (#2788)")
    from datetime import timedelta
    labelled = []
    written = []
    past = tal.now() - timedelta(hours=3)
    led = {"issue": 99, "group": [99], "state": "executing", "reaps": 0,
           "lease": {"session": "dead", "ttl": 2700}, "pr": None, "history": []}
    tal.refs_all = lambda: ["issue-99"]
    tal.parent_of = lambda n, body=None: None
    tal.ledger_read = lambda n: (led, 1, past)
    tal.ledger_write = lambda d, cid, note=None: (written.append(json.loads(json.dumps(d))), 1)[1]
    tal.set_state_labels = lambda n, desired, drop=None, preserve=None: labelled.append(
        (n, set(desired)))
    tal.ref_delete = lambda k: None
    tal.local_unlock = lambda k: None
    tal.orphan_seen_clear = lambda k: None
    tal.open_pr_for_issue_branch = lambda n: None

    tal.reap_leases(dry=False)
    check(written and written[0]["state"] == "queued", "không PR ⇒ queued")
    want = labelled[0][1] if labelled else set()
    check(tal.L_READY in want, "gắn lại agent:ready", str(labelled))
    check(want != set(), "rào chiều: desired rỗng (bug cũ) là ĐỎ", str(labelled))


def test_2788_reap_unmeasurable_pr_does_not_stamp_queued():
    print("reap: không đo được PR ⇒ KHÔNG stamp queued (#2788)")
    from datetime import timedelta
    written = []
    labelled = []
    past = tal.now() - timedelta(hours=3)
    led = {"issue": 50, "group": [50], "state": "executing", "reaps": 0,
           "lease": {"session": "dead", "ttl": 2700}, "pr": None, "history": []}
    tal.refs_all = lambda: ["issue-50"]
    tal.parent_of = lambda n, body=None: None
    tal.ledger_read = lambda n: (led, 1, past)
    tal.ledger_write = lambda d, cid, note=None: (written.append((d.get("state"), note)), 1)[1]
    tal.set_state_labels = lambda *a, **k: labelled.append(a)
    tal.ref_delete = lambda k: None
    tal.local_unlock = lambda k: None
    tal.orphan_seen_clear = lambda k: None
    tal.open_pr_for_issue_branch = lambda n: (_ for _ in ()).throw(tal.Fail("KHÔNG ĐO ĐƯỢC PR"))

    tal.reap_leases(dry=False)
    check(labelled == [], "không đo được ⇒ không ghi nhãn", str(labelled))
    check(written and "queued" not in str(written[0][0] or ""),
          "không stamp state=queued khi không đo được", str(written))
    check(any("KHÔNG đo được" in str(n) for _, n in written),
          "sổ nói rõ không đo được", str(written))


def test_2788_stranded_detector_picks_the_two_measured_cases():
    """Hai ca buổi sáng phải được nhặt; chiều âm: đã có chỗ trong queue thì im."""
    print("stranded_review_candidates: nhặt #2784/#2778, tha #2782/#2757 (#2788)")
    open_prs = [
        {"number": 2785, "headRefName": "issue-2784"},
        {"number": 2780, "headRefName": "issue-2778"},
        {"number": 2787, "headRefName": "issue-2782"},
        {"number": 2759, "headRefName": "issue-2757"},
        {"number": 2775, "headRefName": "dev"},
    ]
    open_labels = {
        2784: {"bug"},
        2778: {"enhancement"},
        2782: {tal.L_AWAIT, tal.L_REVIEWING},
        2757: {tal.L_SHIPPED},
    }
    found = tal.stranded_review_candidates(open_prs, open_labels, set())
    nums = {x["issue"] for x in found}
    check(nums == {2784, 2778},
          "nhặt đúng hai ca mồ côi đã đo", str(found))
    check(2782 not in nums and 2757 not in nums,
          "chiều âm: đã awaiting-review / shipped thì không kêu", str(found))
    found_live = tal.stranded_review_candidates(open_prs, open_labels, {2784})
    check(all(x["issue"] != 2784 for x in found_live),
          "lease sống ⇒ không nhặt (session code còn làm trên PR đã mở)",
          str(found_live))
    # rào: nếu detector bỏ qua nhãn và trả mọi head issue-* thì 2782 lọt vào
    check(len(found) == 2, "không nhặt thừa (rào xanh vô điều kiện)", str(found))

def test_2792_gc_releases_a_worktree_whose_exact_head_was_merged():
    """PR merged + HEAD khớp `headRefOid` ⇒ NHẢ, dù base đã sửa tiếp cùng file.

    Repo squash-merge nên `git cherry` không bao giờ rỗng, và phép so nội dung
    chỉ nhả khi MỌI file worktree chạm còn byte-identical với base — hỏng ngay
    lần đầu có người sửa tiếp một trong số đó. Cửa sổ dọn được vì thế hẹp bằng
    khoảng thời gian tới lần sửa kế tiếp; ngoài cửa sổ đó strand là VĨNH VIỄN,
    kèm một dòng cảnh báo đọc như "có người đang làm dở" về việc đã ship xong.

    Ca thật: worktree `issue-2689`, HEAD `21e99e4dc`, PR #2690 merged, file gây
    strand `base-url-resolver.ts` DIFF vì `origin/dev` MỚI HƠN (#2688 sửa tiếp).
    """
    print("gc: HEAD bằng đúng sha đã merge ⇒ nhả worktree (#2792)")

    # Ca này cần HAI thứ cùng lúc, và fixture dùng chung không cho cả hai:
    #
    #   1. NHIỀU commit trên nhánh — một commit thì patch-id của nó BẰNG patch-id
    #      của commit squash, `git cherry` báo đã-ở-upstream và tầng 1 nhả ngay;
    #   2. base SỬA ĐÈ (không phải nối thêm) — nối thêm cho ra
    #      `added=0, deleted=1` ở diff base→HEAD, rơi vào nhánh phân xử
    #      merge-base mà #2782 đã thêm, và đường cũ cũng tự nhả.
    #
    # Sửa đè thì đi base→HEAD phải ĐẶT LẠI dòng cũ ⇒ `added > 0` ⇒ trúng đúng
    # nhánh thoát-ngay-không-hỏi-ai của #2792.
    with tempfile.TemporaryDirectory() as td:
        root = Path(td)
        up = root / "up.git"
        _git("init", "-q", "--bare", "-b", "main", str(up))
        wt = root / "wt"
        _init_repo(wt, str(up))
        _git("-C", str(wt), "push", "-q", "-u", "origin", "main")
        _git("-C", str(wt), "checkout", "-q", "-b", "issue-2689")
        (wt / "resolver.ts").write_text("// ghi chu cu" + chr(10))
        _git("-C", str(wt), "add", "resolver.ts")
        _git("-C", str(wt), "commit", "-q", "-m", "feat: resolver (1/2)")
        (wt / "resolver.ts").write_text("// ghi chu cu" + chr(10) + "export const x = 1;" + chr(10))
        _git("-C", str(wt), "add", "resolver.ts")
        _git("-C", str(wt), "commit", "-q", "-m", "feat: resolver (2/2)")

        base = root / "base"
        _git("clone", "-q", str(up), str(base))
        for k, v in (("user.email", "tal-test@example.com"), ("user.name", "tal test"),
                     ("commit.gpgsign", "false")):
            _git("-C", str(base), "config", k, v)
        # MỘT commit squash mang trọn nội dung của hai commit trên.
        (base / "resolver.ts").write_text("// ghi chu cu" + chr(10) + "export const x = 1;" + chr(10))
        _git("-C", str(base), "add", "resolver.ts")
        _git("-C", str(base), "commit", "-q", "-m", "feat: resolver (#2689) (squash)")
        # Rồi #2688 SỬA ĐÈ dòng ghi chú vài giờ sau.
        (base / "resolver.ts").write_text("// ghi chu MOI cua #2688" + chr(10) + "export const x = 1;" + chr(10))
        _git("-C", str(base), "add", "resolver.ts")
        _git("-C", str(base), "commit", "-q", "-m", "fix: doi ghi chu")
        _git("-C", str(base), "push", "-q", "origin", "main")
        _git("-C", str(wt), "fetch", "-q", "origin")

        head = tal.run(["git", "rev-parse", "HEAD"], cwd=str(wt), check=False).stdout.strip()

        # Ghim mẫu số: đường CŨ phải GIỮ fixture này. Không có bài này thì bài
        # dưới xanh vô nghĩa — nó sẽ chỉ chứng minh một thứ vốn đã nhả được.
        keep_old = tal.worktree_unmerged_content(wt, "main")
        check(keep_old is not None,
              "mẫu số: nhiều commit + base sửa đè ⇒ đường cũ strand việc đã ship",
              str(keep_old))

        keep = tal.worktree_unmerged_content(wt, "main", merged_head_sha=head)
        check(keep is None,
              "PR merged + sha khớp ⇒ NHẢ, dù base sửa đè cùng file", str(keep))

    # CHIỀU PHẢI GIỮ 1: merged nhưng HEAD có commit THÊM sau khi merge — phần
    # thêm đó chưa ở đâu cả; nhả là `branch -D` nuốt bản sao cuối cùng.
    with tempfile.TemporaryDirectory() as td:
        wt = _squash_merge_fixture(Path(td), land_on_base=True, base_moves_on=True)
        merged_sha = tal.run(["git", "rev-parse", "HEAD"], cwd=str(wt), check=False).stdout.strip()
        (wt / "sau-khi-merge.txt").write_text("việc làm thêm sau khi PR merged\n")
        tal.run(["git", "add", "sau-khi-merge.txt"], cwd=str(wt), check=False)
        tal.run(["git", "commit", "-q", "-m", "feat: them sau khi merge"], cwd=str(wt), check=False)

        keep = tal.worktree_unmerged_content(wt, "main", merged_head_sha=merged_sha)
        check(keep is not None,
              "merged nhưng HEAD đi tiếp ⇒ GIỮ (phần thêm chưa ở remote)", str(keep))

    # CHIỀU PHẢI GIỮ 2: không có PR merged để đối chiếu.
    with tempfile.TemporaryDirectory() as td:
        wt = _squash_merge_fixture(Path(td), land_on_base=False)
        keep = tal.worktree_unmerged_content(wt, "main", merged_head_sha=None)
        check(keep is not None,
              "không PR merged + nội dung chưa tới base ⇒ GIỮ", str(keep))

    # CHIỀU PHẢI GIỮ 3: sha đã merge của một vòng KHÁC.
    with tempfile.TemporaryDirectory() as td:
        wt = _squash_merge_fixture(Path(td), land_on_base=False)
        keep = tal.worktree_unmerged_content(wt, "main", merged_head_sha="0" * 40)
        check(keep is not None,
              "sha đã merge KHÔNG khớp HEAD ⇒ GIỮ, không nhả bừa", str(keep))


def test_2789_shipped_scope_covers_the_whole_cluster():
    """PR CỤM merge xong phải stamp MỌI issue trong `Closes`, không chỉ primary.

    `led["group"]` là cơ chế parent/sub-issue. Từ #2178, gom CỤM là MẶC ĐỊNH và
    các issue trong cụm chỉ nối nhau bằng `Closes #N` trong thân PR. PR #2787
    đóng bảy issue, ledger ghi `"group": [2782]`, và 6/7 rơi lại vào hàng đợi —
    session kế tiếp nhặt việc đã ship và làm lại từ đầu.
    """
    print("ship: group hợp Closes — cụm không rơi khỏi lưới (#2789)")

    body = (
        "Gom cụm cùng vùng file.\n\n"
        "Closes #2782\nCloses #2710\nCloses #2768\n"
        "Closes #2783\nCloses #2786\nCloses #2781\nCloses #2788\n"
    )

    # Ghim mẫu số TRƯỚC: model cũ (chỉ `group`) cho ra ĐÚNG MỘT issue. Không có
    # bài này thì bài dưới xanh mà không chứng minh được nó sửa được gì.
    led = {"group": [2782]}
    check(led.get("group") == [2782],
          "mẫu số: ledger của PR cụm chỉ ghi primary trong group")

    scope = tal.shipped_scope(led, 2782, body)
    check(scope == [2710, 2768, 2781, 2782, 2783, 2786, 2788],
          "group hợp Closes phủ trọn cả bảy issue của cụm", str(scope))

    # Thân PR rỗng ⇒ KHÔNG được mất primary. Đây là đường mọi PR không-cụm đi
    # qua, nên hỏng ở đây là hỏng toàn bộ.
    for empty in (None, "", "không có dòng closes nào"):
        s2 = tal.shipped_scope({"group": [2782]}, 2782, empty)
        check(s2 == [2782], f"thân PR rỗng ⇒ vẫn stamp primary ({empty!r})", str(s2))

    # group (parent/sub) và Closes (cụm) HỢP NHẤT, không cái nào nuốt cái kia.
    s3 = tal.shipped_scope({"group": [100, 101]}, 100, "Closes #200\n")
    check(s3 == [100, 101, 200], "group và Closes hợp nhất, không thay thế nhau", str(s3))

    # issue chính không nằm trong group cũng không được rơi.
    s4 = tal.shipped_scope({}, 42, "Closes #43\n")
    check(s4 == [42, 43], "thiếu group ⇒ vẫn có issue chính + Closes", str(s4))



# ─────────────────────────────────────────────────────────────────────────────
# #2988 — cleanup hậu-merge phải gác bằng PHÉP ĐO, không bằng exit code
#
# Ba việc ngay sau merge đều KHÔNG rollback được: xoá worktree, XOÁ NHÁNH
# REMOTE, ghi ledger `shipped`. Chạy chúng trên một merge chưa landed thì PR
# không mở lại được (`gh pr reopen` cần head branch còn sống) và công việc chỉ
# còn trong worktree cục bộ — thứ `tal gc` được phép dọn.
#
# Đã xảy ra ở #2974: hai PR `mergedAt=null`, nhánh biến mất, `origin/dev` vẫn
# mang bản CHƯA vá.
# ─────────────────────────────────────────────────────────────────────────────

def _stub_ancestry(is_ancestor: bool) -> list[list[str]]:
    """Thay `run` để chỉ điều khiển đúng `merge-base --is-ancestor`."""
    cmds: list[list[str]] = []

    def fake_run(cmd, cwd=None, check=True, stdin=None):
        cmds.append(cmd)

        class R:
            stdout = ""
            stderr = ""
            returncode = 0 if ("--is-ancestor" not in cmd or is_ancestor) else 1
        return R()

    tal.run = fake_run

    return cmds


def test_assert_merge_landed_blocks_when_head_not_in_base():
    print("#2988 merge trả 0 nhưng SHA chưa vào base ⇒ Fail, không cho dọn gì")

    _stub_ancestry(is_ancestor=False)

    try:
        tal.assert_merge_landed(777, "dead" * 10)
    except tal.Fail as e:
        assert "KHÔNG phải tổ tiên" in str(e), str(e)
        # Thông điệp phải nói rõ trạng thái CÒN NGUYÊN — người đọc nó đang lo
        # mất việc, và câu này là thứ ngăn họ đi xoá tay cho "sạch".
        assert "GIỮ NGUYÊN" in str(e), str(e)
        print("  ok   chặn, và nói rõ trạng thái còn nguyên")
    else:
        raise AssertionError("KHÔNG chặn — cleanup sẽ xoá nhánh của một merge chưa landed")


def test_assert_merge_landed_is_silent_on_a_real_merge():
    print("#2988 merge thật ⇒ im, không thêm bước nào vào đường thành công")

    _stub_ancestry(is_ancestor=True)
    tal.assert_merge_landed(777, "cafe" * 10)
    print("  ok   im khi đã landed")


def test_assert_merge_landed_does_not_cry_wolf_without_a_sha():
    print("#2988 không hỏi được head ⇒ im, KHÔNG chặn oan")

    cmds = _stub_ancestry(is_ancestor=False)
    tal.assert_merge_landed(777, "")
    # Rào kêu oan thì bị TẮT, và lúc đó nó không canh gì nữa. Không có sha thì
    # không có gì để đo — im là đúng, và phải im mà không chạy git luôn.
    assert cmds == [], f"đã chạy git dù không có sha để đo: {cmds}"
    print("  ok   im, và không chạy git")


def test_cmd_merge_does_not_delete_the_branch_when_nothing_landed():
    print("#2988 ĐƯỜNG THẬT: merge trả 0, head chưa vào base ⇒ Fail và KHÔNG xoá nhánh")

    # Ba ca trên test HÀM. Ca này test CHỖ NỐI — gỡ `assert_merge_landed(...)`
    # khỏi `cmd_merge` thì ba ca kia vẫn xanh, và rào thành vùng không ai canh.
    calls: list[list[str]] = []
    removed: list[int] = []

    tal.gh = _gh_stub_with_head(calls)
    tal.run = _run_stub_ancestor(False)          # ← merge "thành công" mà CHƯA landed
    tal.gh_json = lambda args, default=None: {"baseRefName": "dev"}
    tal.ref_exists = lambda k: False
    tal.merge_blockers = lambda pr, require_ci=True: (10, [], ("abc123abc123", "9"))
    tal.pr_checks = lambda pr: ("pass", [])
    tal.pr_dangling_pointers = lambda pr: []
    tal.base_red_hint = lambda names: ""
    tal.session_id = lambda: "deadbeefcafe0000"
    tal.remove_worktree = lambda i: removed.append(i)
    tal.ledger_read = lambda i: ({"issue": 10, "group": [10], "history": []}, 1, tal.now())
    written: list = []
    tal.ledger_write = lambda led, cid, note=None: (written.append(note), 1)[1]
    tal.set_state_labels = lambda *a, **k: None
    tal.closable = lambda n, m=None: (False, "")

    class A:
        pr = 42
        force = False
        no_subs = True
        self_merge = False
        note = None
        require_ci = False
        batch_verified = True
        json = False
        verdict_ev = ("abc123abc123", "9")

    try:
        tal.cmd_merge(A())
        check(False, "merge chưa landed mà cmd_merge đi qua", "không Fail")
    except tal.Fail as e:
        check("KHÔNG phải tổ tiên" in str(e), "Fail vì chưa landed", str(e)[:120])

    # Ba tác dụng phụ KHÔNG rollback được — cả ba phải chưa xảy ra.
    check(not any("DELETE" in a and any("refs/heads/issue-" in x for x in a) for a in calls),
          "KHÔNG xoá nhánh remote", str(calls))
    check(removed == [], "KHÔNG dọn worktree", str(removed))
    check(written == [], "KHÔNG ghi ledger shipped", str(written))
    restore_tal()


def test_cmd_merge_refuses_before_merging_when_head_cannot_be_measured():
    print("#2988 API hỏng ⇒ Fail TRƯỚC `gh pr merge` — không đo được thì chưa merge gì cả")

    # Đường khai thác thật: `gh` thoát 502. Với `gh_json` (nuốt lỗi) thì
    # `head_before` = "" ⇒ rào ancestry im lặng cho qua ⇒ cleanup phá huỷ chạy
    # y như trước bản vá. "Không đo được" KHÔNG phải "đo xong, không có gì".
    calls: list[list[str]] = []
    removed: list[int] = []

    def gh_502(args, check=True, stdin=None):
        calls.append(args)
        if "headRefOid" in args:
            return type("R", (), {"returncode": 1, "stdout": "",
                                  "stderr": "HTTP 502: Bad gateway"})()
        return type("R", (), {"returncode": 0, "stdout": "", "stderr": ""})()

    tal.gh = gh_502
    tal.run = _run_stub_ancestor(True)
    tal.gh_json = lambda args, default=None: {"baseRefName": "dev"}
    tal.ref_exists = lambda k: False
    tal.merge_blockers = lambda pr, require_ci=True: (10, [], ("abc123abc123", "9"))
    tal.pr_checks = lambda pr: ("pass", [])
    tal.pr_dangling_pointers = lambda pr: []
    tal.base_red_hint = lambda names: ""
    tal.session_id = lambda: "deadbeefcafe0000"
    tal.remove_worktree = lambda i: removed.append(i)
    tal.ledger_read = lambda i: ({"issue": 10, "group": [10], "history": []}, 1, tal.now())
    tal.ledger_write = lambda led, cid, note=None: 1
    tal.set_state_labels = lambda *a, **k: None
    tal.closable = lambda n, m=None: (False, "")

    class A:
        pr = 42
        force = False
        no_subs = True
        self_merge = False
        note = None
        require_ci = False
        batch_verified = True
        json = False
        verdict_ev = ("abc123abc123", "9")

    try:
        tal.cmd_merge(A())
        check(False, "API hỏng mà vẫn đi qua", "không Fail")
    except tal.Fail as e:
        check("KHÔNG ĐO ĐƯỢC" in str(e), "Fail vì không đo được, không phải vì rỗng",
              str(e)[:120])

    # Vế đắt nhất: chưa merge gì cả — sạch hơn hẳn so với chặn SAU merge.
    check(not any(c[:2] == ["pr", "merge"] for c in calls),
          "chưa gọi `gh pr merge` lần nào", str(calls))
    check(removed == [], "KHÔNG dọn worktree", str(removed))
    restore_tal()


def test_cmd_merge_refuses_when_gh_succeeds_but_returns_no_head():
    print("#2991 gh thoát 0 với stdout RỖNG ⇒ Fail — 'đo được, rỗng' vẫn không phải một head SHA")

    # Ca này KHÁC ca 502 ngay trên, và khác ở đúng chỗ khiến nó bị bỏ sót:
    # `gh_json_strict` chỉ RAISE khi `returncode != 0`. Thoát 0 với stdout rỗng
    # thì nó `return []` — hợp lệ, có chủ đích, dùng cho truy vấn mà "rỗng" là
    # câu trả lời thật. Nhưng head SHA thì KHÔNG có nghĩa rỗng nào cả.
    #
    # Nên thứ duy nhất chặn đường này là vế `if not head_before: raise Fail`,
    # và đo được ở #2991 là gỡ riêng vế đó ra thì **136 ca vẫn xanh** — tức
    # nhánh với tới được mà không rào nào giữ. Cùng bài học với #2988: ghim
    # CHỖ NỐI, không chỉ ghim hàm.
    calls: list[list[str]] = []
    removed: list[int] = []

    def gh_empty(args, check=True, stdin=None):
        calls.append(args)
        # returncode 0 — `gh_json_strict` KHÔNG raise ở đây, nó trả [].
        return type("R", (), {"returncode": 0, "stdout": "", "stderr": ""})()

    tal.gh = gh_empty
    tal.run = _run_stub_ancestor(True)
    tal.gh_json = lambda args, default=None: {"baseRefName": "dev"}
    tal.ref_exists = lambda k: False
    tal.merge_blockers = lambda pr, require_ci=True: (10, [], ("abc123abc123", "9"))
    tal.pr_checks = lambda pr: ("pass", [])
    tal.pr_dangling_pointers = lambda pr: []
    tal.base_red_hint = lambda names: ""
    tal.session_id = lambda: "deadbeefcafe0000"
    tal.remove_worktree = lambda i: removed.append(i)
    tal.ledger_read = lambda i: ({"issue": 10, "group": [10], "history": []}, 1, tal.now())
    tal.ledger_write = lambda led, cid, note=None: 1
    tal.set_state_labels = lambda *a, **k: None
    tal.closable = lambda n, m=None: (False, "")

    class A:
        pr = 42
        force = False
        no_subs = True
        self_merge = False
        note = None
        require_ci = False
        batch_verified = True
        json = False
        verdict_ev = ("abc123abc123", "9")

    try:
        tal.cmd_merge(A())
        check(False, "gh trả rỗng mà vẫn đi qua", "không Fail")
    except tal.Fail as e:
        check("KHÔNG ĐO ĐƯỢC head SHA" in str(e),
              "Fail nói rõ là không đo được head SHA", str(e)[:120])

    # Ba tác dụng phụ không rollback được đều chưa xảy ra.
    check(not any(c[:2] == ["pr", "merge"] for c in calls),
          "chưa gọi `gh pr merge` lần nào", str(calls))
    check(not any("refs/heads/" in " ".join(c) for c in calls),
          "chưa xoá nhánh remote", str(calls))
    check(removed == [], "KHÔNG dọn worktree", str(removed))
    restore_tal()


def test_merge_uses_merge_commit_so_ancestry_holds():
    print("#2988 phép đo gắn với --merge; đổi sang --squash phải sửa cả hai vế")

    src = (HERE / "tal").read_text(encoding="utf-8")
    # Đổi sang `--squash` thì nội dung vào base dưới SHA KHÁC, và rào ancestry
    # sẽ kêu oan MỌI lượt merge — rào kêu oan không bị tranh luận, nó bị TẮT.
    assert '"pr", "merge", str(pr), "-R", C.repo, "--merge"' in src, (
        "đường merge không còn dùng `--merge` — rào ancestry của #2988 phải được "
        "sửa cùng lúc, nếu không nó chặn nhầm mọi lượt merge hợp lệ"
    )
    print("  ok   --merge còn nguyên")


if __name__ == "__main__":
    sys.exit(main())

