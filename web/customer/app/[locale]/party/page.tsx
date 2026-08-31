import Header from "@/components/Header";
import PartyStory from "@/components/party-story";
import Footer from "@/components/Footer";
import ScrollReveal from "@/components/scroll-reveal";

export const metadata = {
  title: "Tiệc & Sự kiện · ケータリング · Viet Origin / ベト屋",
};

export default function PartyPage() {
  return (
    <>
      <Header hideSwitcher showBack showLogo branchListHref="/select-branch" />
      <PartyStory />
      <Footer />
      <ScrollReveal />
    </>
  );
}
