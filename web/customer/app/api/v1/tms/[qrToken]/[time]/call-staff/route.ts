import { NextRequest, NextResponse } from "next/server";

export async function POST(
  _req: NextRequest,
  { params }: { params: Promise<{ qrToken: string; time: string }> },
) {
  const { qrToken, time } = await params;

  // TODO: xác thực qrToken hợp lệ, ghi log, broadcast đến nhân viên (Reverb/WebSocket)
  return NextResponse.json(
    { message: "Staff called", qrToken, time },
    { status: 200 },
  );
}
