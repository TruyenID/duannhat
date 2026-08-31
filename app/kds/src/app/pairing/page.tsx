import PairingForm from "./components/pairing-form";
import { useTranslation } from "@/i18n";

export default function PairingPage() {
  const { t } = useTranslation();
  return (
    <div className="min-h-screen grid place-items-center bg-background text-foreground p-4">
      <div className="w-full max-w-md space-y-6">
        <header className="text-center space-y-2">
          <h1 className="text-3xl font-semibold">{t("pairing.title")}</h1>
          <p className="text-muted-foreground">{t("pairing.subtitle")}</p>
        </header>
        <PairingForm />
      </div>
    </div>
  );
}
