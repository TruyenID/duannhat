import {
  BanknoteIcon,
  CreditCardIcon,
  LandmarkIcon,
  SmartphoneIcon,
} from "lucide-react";

/**
 * Tile icon for a payment method `code`. The codes are backend rows (see
 * `GET /pos/payment-methods`), not a closed enum, so unknown codes are
 * expected — they fall through to the generic wallet/phone icon rather
 * than blowing up.
 */
export function iconFor(code: string): typeof BanknoteIcon {
  switch (code) {
    case "cash":
      return BanknoteIcon;
    case "card":
    case "card_terminal":
      return CreditCardIcon;
    case "transfer":
    case "bank":
      return LandmarkIcon;
    default:
      return SmartphoneIcon;
  }
}
