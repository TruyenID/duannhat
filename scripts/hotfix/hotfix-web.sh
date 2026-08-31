#!/usr/bin/env bash
# Release một app web thẳng trên Amplify — không đi qua hàng đợi GitHub.
#
#   scripts/hotfix/hotfix-web.sh <admin|customer|pos>
#
# Amplify tự clone nhánh main mới nhất và build, nên điều kiện duy nhất là
# COMMIT ĐÃ NẰM TRÊN MAIN — script này không upload code local, nó chỉ thay
# workflow GitHub ra lệnh cho Amplify. Push buildspec TRƯỚC rồi mới start-job
# và có nghỉ giữa hai bước: đo 2026-08-18, push xong start ngay thì job chụp
# spec CŨ (eventual consistency phía Amplify) và dấu vân tay sai cả lượt.
#
# Cái nó BỎ QUA: hàng đợi runner + cổng CI của GitHub.
# Cái nó KHÔNG bỏ qua: chờ job SUCCEED và smoke /build-info.json — in ra
# commit mà bundle đang phục vụ để mắt người đối chiếu.
set -euo pipefail

APP=${1:?usage: hotfix-web.sh <admin|customer|pos>}
case "$APP" in
  admin)    APP_ID=d3cqu96a6b470f ;;
  customer) APP_ID=d3bw22hyw76201 ;;
  pos)      APP_ID=d3nuz12zp9crpd ;;
  *) echo "app phải là admin|customer|pos"; exit 1 ;;
esac
export AWS_DEFAULT_REGION=${AWS_DEFAULT_REGION:-ap-northeast-1}
ROOT=$(git rev-parse --show-toplevel)
SPEC="$ROOT/.github/amplify/${APP}-buildspec.yml"

echo "==> push buildspec ($APP)"
aws amplify update-app --app-id "$APP_ID" --build-spec "$(cat "$SPEC")" \
  --query 'app.appId' --output text >/dev/null
sleep 15 # để update-app lắng xuống trước khi job chụp spec

echo "==> start RELEASE job"
JOB=$(aws amplify start-job --app-id "$APP_ID" --branch-name main \
  --job-type RELEASE --query 'jobSummary.jobId' --output text)
echo "    job $JOB"

while true; do
  STATUS=$(aws amplify get-job --app-id "$APP_ID" --branch-name main \
    --job-id "$JOB" --query 'job.summary.status' --output text)
  case "$STATUS" in
    SUCCEED) break ;;
    FAILED|CANCELLED)
      echo "Amplify job $STATUS — log:"
      aws amplify get-job --app-id "$APP_ID" --branch-name main --job-id "$JOB" \
        --query 'job.steps[?status==`FAILED`].logUrl' --output text
      exit 1 ;;
    *) echo "    $STATUS…"; sleep 20 ;;
  esac
done

echo "==> smoke"
curl -sf -m 30 "https://main.${APP_ID}.amplifyapp.com/" -o /dev/null \
  && echo "    / OK"
INFO=$(curl -sf -m 30 "https://main.${APP_ID}.amplifyapp.com/build-info.json" || echo '{}')
echo "    build-info: $INFO"
echo "==> OK — đối chiếu commit trên với 'git log origin/main' bằng mắt"
