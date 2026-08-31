import { describe, expect, expectTypeOf, it } from 'vitest';
// Import from the manual wrapper module directly — the generated barrel
// (index.ts) only re-exports the schema-derived surface, and the regen
// dropped the hand-written form helpers from it.
import {
  buildPaymentGatewayConnectionCreatePayload,
  buildPaymentGatewayConnectionUpdatePayload,
  emptyPaymentGatewayConnectionCreateForm,
  paymentGatewayConnectionCreateSchema,
  paymentGatewayConnectionSchemas,
  paymentGatewayConnectionUpdateSchema,
  type PaymentGatewayConnection,
  type PaymentGatewayConnectionCreate,
  type PaymentGatewayConnectionCreateFormState,
  type PaymentGatewayConnectionUpdate,
  type PaymentGatewayConnectionUpdateFormState,
} from './PaymentGatewayConnection';

type HiddenField = 'secret_ref' | 'webhook_secret_ref' | 'secret_version' | 'key_fingerprint';

const hiddenFields: HiddenField[] = [
  'secret_ref',
  'webhook_secret_ref',
  'secret_version',
  'key_fingerprint',
];

describe('PaymentGatewayConnection public contract', () => {
  it('excludes hidden fields from all public index types', () => {
    expectTypeOf<Extract<HiddenField, keyof PaymentGatewayConnection>>().toEqualTypeOf<never>();
    expectTypeOf<Extract<HiddenField, keyof PaymentGatewayConnectionCreate>>().toEqualTypeOf<never>();
    expectTypeOf<Extract<HiddenField, keyof PaymentGatewayConnectionUpdate>>().toEqualTypeOf<never>();
    expectTypeOf<
      Extract<HiddenField, keyof PaymentGatewayConnectionCreateFormState>
    >().toEqualTypeOf<never>();
    expectTypeOf<
      Extract<HiddenField, keyof PaymentGatewayConnectionUpdateFormState>
    >().toEqualTypeOf<never>();
  });

  it('strips hidden fields from public schemas', () => {
    const maliciousInput = {
      identity_brand_id: 'identity-brand-1',
      owner_scope: 'hq',
      // #3074 — cột DẪN XUẤT, mang khoá UNIQUE tự nhiên. Giá trị gửi lên không
      // có tác dụng: `PaymentGatewayConnection` đóng dấu lại ở `saving`. Nó có
      // mặt ở đây chỉ vì cột không-NULL nên schema tạo đòi, đúng như mọi cột
      // không-NULL khác trong bảng.
      owner_branch_key: '00000000-0000-0000-0000-000000000000',
      brand_owner_org_unit_id: 'owner-unit-1',
      operator_org_unit_id: 'operator-unit-1',
      ownership_revision: '1',
      environment: 'test',
      merchant_account_id: 'merchant-1',
      charge_model: 'direct',
      health: 'ready',
      is_active: true,
      secret_ref: 'vault://secret',
      webhook_secret_ref: 'vault://webhook',
      secret_version: '42',
      key_fingerprint: 'fingerprint',
    };

    const created = paymentGatewayConnectionCreateSchema.parse(maliciousInput);
    const updated = paymentGatewayConnectionUpdateSchema.parse(maliciousInput);

    for (const field of hiddenFields) {
      expect(paymentGatewayConnectionSchemas).not.toHaveProperty(field);
      expect(created).not.toHaveProperty(field);
      expect(updated).not.toHaveProperty(field);
    }
  });

  it('strips hidden fields from form defaults and payload builders', () => {
    const form = emptyPaymentGatewayConnectionCreateForm();
    const maliciousForm = Object.assign({ ...form }, {
      secret_ref: 'vault://secret',
      webhook_secret_ref: 'vault://webhook',
      secret_version: '42',
      key_fingerprint: 'fingerprint',
    });

    const created = buildPaymentGatewayConnectionCreatePayload(maliciousForm);
    const updated = buildPaymentGatewayConnectionUpdatePayload(maliciousForm);

    for (const field of hiddenFields) {
      expect(form).not.toHaveProperty(field);
      expect(created).not.toHaveProperty(field);
      expect(updated).not.toHaveProperty(field);
    }
  });
});
