#!/usr/bin/env bash
# Deploy backend production NGAY — đường workflow_dispatch, kèm tùy chọn dọn
# hàng đợi runner.
#
#   scripts/hotfix/hotfix-backend.sh [--preempt]
#
# Backend KHÔNG có làn "không CI/CD" từ máy cá nhân: key SSH của xserver chỉ
# nằm trong GitHub secret (đo 2026-08-18: không key nào ở máy dev mở được
# famgia@famgia.xbiz.jp), và bước deploy chạy migrate + seed vào DB tiền thật
# — thứ đó PHẢI đi qua đúng một đường được review. workflow_dispatch chính là
# làn hotfix có chủ đích: nó MIỄN rào giờ phục vụ (23:00–09:00 JST) vì có
# người đang nhìn màn hình.
#
# Điểm nghẽn thật của hotfix là RUNNER: một máy self-hosted chạy tuần tự, nên
# dispatch xếp sau cả loạt CI của chính merge vừa đẩy (đo 2026-08-18: deploy
# nằm queued ~15 phút sau backend-tests + workstation-go). --preempt hủy các
# run còn QUEUED (không đụng run đang chạy) trên main để deploy lên lượt —
# dấu X trên các run bị hủy là chi phí phải trả, re-run chúng sau khi dập lửa.
set -euo pipefail

PREEMPT=${1:-}
REPO=godx-jp/godx-tempo

if [ "$PREEMPT" = "--preempt" ]; then
  echo "==> hủy các run còn queued trên main để nhường runner"
  gh run list -R "$REPO" --branch main --status queued --limit 20 \
    --json databaseId,name -q '.[] | "\(.databaseId) \(.name)"' |
  while read -r id name; do
    echo "    cancel $id ($name)"
    gh run cancel "$id" -R "$REPO" || true
  done
fi

echo "==> dispatch deploy-xserver (ref main)"
gh workflow run deploy-xserver.yml -R "$REPO" --ref main
sleep 10
RUN=$(gh run list -R "$REPO" --workflow deploy-xserver.yml --limit 5 \
  --json databaseId,event,status \
  -q '[.[] | select(.event=="workflow_dispatch" and .status!="completed")][0].databaseId')
[ -n "$RUN" ] && [ "$RUN" != "null" ] || { echo "không tìm thấy run vừa dispatch"; exit 1; }
echo "==> watch run $RUN (deploy chạy migrate --force + seed vào DB thật)"
gh run watch "$RUN" -R "$REPO" --exit-status
echo "==> OK — deploy xanh, smoke của workflow đã tự chạy"
