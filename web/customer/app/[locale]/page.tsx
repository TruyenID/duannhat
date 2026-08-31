import Header from "@/components/Header";
import BlogExcerptSection from "@/components/blog-excerpt-section";
import HomeFeaturedDishes from "@/components/home-featured-dishes";
import HomeGallery from "@/components/home-gallery";
import HomeStory from "@/components/home-story";
import ScrollReveal from "@/components/scroll-reveal";

export default async function Home({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  // Await params to satisfy Next.js 15+ requirements
  await params;

  return (
    <>
	      <Header hideSwitcher showLogo hideRegister />
      <HomeStory />
      <HomeFeaturedDishes />
      <BlogExcerptSection />
      <HomeGallery />
      <ScrollReveal />
    </>
  );
}
