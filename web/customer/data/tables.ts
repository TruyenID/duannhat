export type TableStatus = "available" | "occupied" | "reserved" | "paid";

export interface Table {
  id: string;
  number: string;
  seats: number;
  status: TableStatus;
  qr_token: string;
}

export interface Zone {
  id: string;
  name: string;
  tables: Table[];
}

export const zones: Zone[] = [
  {
    id: "zone-a",
    name: "Khu A",
    tables: [
      { id: "t-a1", number: "A1", seats: 2, status: "available", qr_token: "tkA1aBcDeFgHiJkL" },
      { id: "t-a2", number: "A2", seats: 2, status: "occupied",  qr_token: "tkA2bCdEfGhIjKlM" },
      { id: "t-a3", number: "A3", seats: 4, status: "available", qr_token: "tkA3cDeFgHiJkLmN" },
      { id: "t-a4", number: "A4", seats: 4, status: "available", qr_token: "tkA4dEfGhIjKlMnO" },
      { id: "t-a5", number: "A5", seats: 4, status: "reserved",  qr_token: "tkA5eFgHiJkLmNpQ" },
      { id: "t-a6", number: "A6", seats: 6, status: "available", qr_token: "tkA6fGhIjKlMnOpQ" },
    ],
  },
  {
    id: "zone-b",
    name: "Khu B",
    tables: [
      { id: "t-b1", number: "B1", seats: 2, status: "occupied",  qr_token: "tkB1gHiJkLmNoPqR" },
      { id: "t-b2", number: "B2", seats: 2, status: "available", qr_token: "tkB2hIjKlMnOpQrS" },
      { id: "t-b3", number: "B3", seats: 4, status: "available", qr_token: "tkB3iJkLmNoPqRsT" },
      { id: "t-b4", number: "B4", seats: 6, status: "available", qr_token: "tkB4jKlMnOpQrStU" },
      { id: "t-b5", number: "B5", seats: 6, status: "occupied",  qr_token: "tkB5kLmNoPqRsTuV" },
      { id: "t-b6", number: "B6", seats: 8, status: "available", qr_token: "tkB6lMnoPqRsTuVw" },
    ],
  },
  {
    id: "zone-vip",
    name: "Phòng VIP",
    tables: [
      { id: "t-v1", number: "V1", seats: 6,  status: "reserved",  qr_token: "tkV1mNoPqRsTuVwX" },
      { id: "t-v2", number: "V2", seats: 8,  status: "available", qr_token: "tkV2nOpQrStUvWxY" },
      { id: "t-v3", number: "V3", seats: 10, status: "available", qr_token: "tkV3oPqRsTuVwXyZ" },
    ],
  },
  {
    id: "zone-out",
    name: "Ngoài trời",
    tables: [
      { id: "t-o1", number: "O1", seats: 2, status: "available", qr_token: "tkO1pQrStUvWxYzA" },
      { id: "t-o2", number: "O2", seats: 2, status: "available", qr_token: "tkO2qRsTuVwXyZaB" },
      { id: "t-o3", number: "O3", seats: 4, status: "occupied",  qr_token: "tkO3rStUvWxYzAbC" },
      { id: "t-o4", number: "O4", seats: 4, status: "available", qr_token: "tkO4sTuVwXyZaBcD" },
    ],
  },
];

export function findTableByToken(token: string): { table: Table; zone: Zone } | null {
  for (const zone of zones) {
    const table = zone.tables.find((t) => t.qr_token === token);
    if (table) return { table, zone };
  }
  return null;
}
