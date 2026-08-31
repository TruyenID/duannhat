"use client";

import { useEffect, useState } from "react";

type AncestorInfo = {
  tag: string;
  overflow: string;
  overflowX: string;
  overflowY: string;
  position: string;
  display: string;
  height: string;
};

type DebugInfo = {
  element: {
    position: string;
    top: string;
    zIndex: string;
    display: string;
  };
  ancestors: AncestorInfo[];
};

export default function StickyDebugPage() {
  const [debugInfo, setDebugInfo] = useState<DebugInfo | null>(null);

  useEffect(() => {
    const stickyEl = document.querySelector('[data-sticky-test]');
    if (!stickyEl) return;

    const computed = window.getComputedStyle(stickyEl);
    const ancestors: AncestorInfo[] = [];
    
    let parent = stickyEl.parentElement;
    while (parent && parent !== document.body.parentElement) {
      const style = window.getComputedStyle(parent);
      ancestors.push({
        tag: parent.tagName.toLowerCase(),
        overflow: style.overflow,
        overflowX: style.overflowX,
        overflowY: style.overflowY,
        position: style.position,
        display: style.display,
        height: style.height,
      });
      parent = parent.parentElement;
    }

    // eslint-disable-next-line react-hooks/set-state-in-effect
    setDebugInfo({
      element: {
        position: computed.position,
        top: computed.top,
        zIndex: computed.zIndex,
        display: computed.display,
      },
      ancestors,
    });
  }, []);

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Sticky Test Element */}
      <header 
        data-sticky-test
        className="sticky top-0 z-50 bg-blue-600 text-white p-4 shadow-md"
      >
        <h1 className="text-xl font-bold">Sticky Header Test</h1>
      </header>

      <div className="p-8">
        <div className="bg-white rounded-lg p-6 shadow mb-8">
          <h2 className="text-2xl font-bold mb-4">Debug Info</h2>
          {debugInfo ? (
            <div className="space-y-4">
              <div>
                <h3 className="font-bold text-lg mb-2">Element Computed Styles:</h3>
                <pre className="bg-gray-100 p-3 rounded text-sm overflow-auto">
                  {JSON.stringify(debugInfo.element, null, 2)}
                </pre>
                {debugInfo.element.position !== 'sticky' && (
                  <p className="mt-2 text-red-600 font-semibold">
                    ⚠️ VẤN ĐỀ: position không phải &quot;sticky&quot; - đã bị override!
                  </p>
                )}
              </div>

              <div>
                <h3 className="font-bold text-lg mb-2">Ancestor Elements:</h3>
                {debugInfo.ancestors.map((anc, i) => {
                  const hasOverflow = anc.overflow !== 'visible' || 
                                     anc.overflowX !== 'visible' || 
                                     anc.overflowY !== 'visible';
                  
                  return (
                    <div 
                      key={i} 
                      className={`mb-3 p-3 rounded ${hasOverflow ? 'bg-red-50 border-2 border-red-500' : 'bg-gray-100'}`}
                    >
                      <p className="font-mono text-sm font-bold mb-1">&lt;{anc.tag}&gt;</p>
                      <pre className="text-xs">
                        {JSON.stringify(anc, null, 2)}
                      </pre>
                      {hasOverflow && (
                        <p className="mt-2 text-red-600 text-sm font-bold">
                          🚨 NGUYÊN NHÂN: Element này có overflow !== visible
                        </p>
                      )}
                    </div>
                  );
                })}
              </div>
            </div>
          ) : (
            <p className="text-gray-500">Loading debug info...</p>
          )}
        </div>

        {/* Scroll content */}
        <div className="space-y-4">
          <h2 className="text-2xl font-bold mb-4">Scroll xuống để test sticky:</h2>
          {Array.from({ length: 20 }).map((_, i) => (
            <div key={i} className="bg-white p-6 rounded-lg shadow">
              <h3 className="font-bold text-lg">Section {i + 1}</h3>
              <p className="text-gray-600">
                Nếu header màu xanh &quot;dính&quot; ở top khi scroll → sticky hoạt động ✅
                <br />
                Nếu header biến mất khi scroll → sticky KHÔNG hoạt động ❌
              </p>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
