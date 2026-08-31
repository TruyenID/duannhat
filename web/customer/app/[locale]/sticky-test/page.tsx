export default function StickyTestPage() {
  return (
    <div className="min-h-screen bg-gray-50">
      {/* Main Header */}
      <header className="sticky top-0 z-50 bg-blue-600 text-white shadow-md">
        <div className="mx-auto max-w-7xl px-4 py-3">
          <h1 className="text-lg font-bold">Main Header (z-50, top-0)</h1>
        </div>
      </header>

      {/* Content before sticky menu */}
      <div className="mx-auto max-w-7xl px-4 py-8">
        <div className="rounded-lg bg-white p-8 shadow">
          <h2 className="mb-4 text-2xl font-bold">Banner / Featured Content</h2>
          <p className="text-gray-600">
            This is some content before the sticky menu. Scroll down to see if the menu sticks below the header.
          </p>
        </div>
      </div>

      {/* Sticky Menu Header */}
      <div className="sticky top-12 z-40 bg-green-600 text-white shadow-md">
        <div className="mx-auto max-w-7xl px-4 py-3">
          <h2 className="text-base font-bold">Sticky Menu Header (z-40, top-12)</h2>
        </div>
        {/* Category Tabs */}
        <div className="bg-green-700">
          <div className="mx-auto max-w-7xl px-4 py-2">
            <div className="flex gap-2 overflow-x-auto">
              {['Category 1', 'Category 2', 'Category 3', 'Category 4', 'Category 5'].map((cat) => (
                <button
                  key={cat}
                  className="shrink-0 rounded-full bg-white px-4 py-2 text-sm font-medium text-green-700"
                >
                  {cat}
                </button>
              ))}
            </div>
          </div>
        </div>
      </div>

      {/* Main Content */}
      <main className="mx-auto max-w-7xl px-4 py-8">
        {Array.from({ length: 20 }).map((_, i) => (
          <div key={i} className="mb-6 rounded-lg bg-white p-6 shadow">
            <h3 className="mb-2 text-xl font-bold">Section {i + 1}</h3>
            <p className="text-gray-600">
              Lorem ipsum dolor sit amet, consectetur adipiscing elit. Scroll down to test if the
              sticky menu header stays below the main header (48px offset on mobile, 56px on desktop).
            </p>
          </div>
        ))}
      </main>
    </div>
  );
}
