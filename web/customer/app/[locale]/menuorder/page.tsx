import Header from "@/components/Header";
import BlogExcerptSection from "@/components/blog-excerpt-section";
import HomeHero from "@/components/home-hero";

export default function MenuOrderPage() {
  return (
    // Page bg theo design system flow Dine-in/Takeaway — #FAFAFA phủ ngoài
    // Header + hero + blog excerpt.
    <div className="min-h-screen bg-[#FAFAFA]">
	      <Header hideSwitcher showLogo />
      <HomeHero />
      <BlogExcerptSection />
    </div>
  );
}
