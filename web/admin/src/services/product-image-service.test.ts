import { describe, it, expect, vi, beforeEach } from "vitest";
import { productImageService } from "./product-image-service";

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

function mockOk(data: unknown, status = 200) {
  mockFetch.mockResolvedValueOnce({
    ok: true,
    status,
    json: () => Promise.resolve(data),
  });
}

beforeEach(() => {
  mockFetch.mockReset();
});

describe("productImageService", () => {
  // -------------------------------------------------------------------------
  //  uploadImage
  // -------------------------------------------------------------------------

  describe("uploadImage", () => {
    it("POSTs to /api/v1/files/upload as multipart FormData", async () => {
      mockOk({
        data: {
          id: "file-1",
          url: "http://localhost:5490/tempo/uploads/temp/x.jpg",
          sort_order: 0,
          original_name: "x.jpg",
          is_permanent: false,
        },
      });

      const file = new File(["dummy"], "x.jpg", { type: "image/jpeg" });
      await productImageService.uploadImage(file);

      const [url, opts] = mockFetch.mock.calls[0];
      expect(url).toContain("/api/v1/files/upload");
      expect(opts.method).toBe("POST");
      expect(opts.body).toBeInstanceOf(FormData);

      // FormData must include both `file` and `collection: "gallery"`.
      const fd = opts.body as FormData;
      expect(fd.get("file")).toBe(file);
      expect(fd.get("collection")).toBe("gallery");
    });

    it("does NOT set Content-Type header (browser sets multipart boundary)", async () => {
      mockOk({ data: { id: "file-1" } });

      const file = new File(["dummy"], "x.jpg", { type: "image/jpeg" });
      await productImageService.uploadImage(file);

      const [, opts] = mockFetch.mock.calls[0];
      // apiFetch detects FormData and lets the browser handle Content-Type
      expect(opts.headers["Content-Type"]).toBeUndefined();
    });

    it("returns the temp file record, with the MinIO URL proxied to same-origin", async () => {
      mockOk({
        data: {
          id: "file-1",
          url: "http://localhost:5490/tempo/uploads/temp/x.jpg",
          sort_order: 0,
          original_name: "x.jpg",
          is_permanent: false,
        },
      });

      const file = new File(["dummy"], "x.jpg", { type: "image/jpeg" });
      const result = await productImageService.uploadImage(file);

      // `apiFetch` deep-rewrites MinIO hosts to `/s3/` (see lib/image-url.ts)
      // so the browser loads the image through this app's own origin instead
      // of the storage host. Everything else is passed through untouched.
      expect(result.data).toEqual({
        id: "file-1",
        url: "/s3/uploads/temp/x.jpg",
        sort_order: 0,
        original_name: "x.jpg",
        is_permanent: false,
      });
    });
  });

  // -------------------------------------------------------------------------
  //  syncImages
  // -------------------------------------------------------------------------

  describe("syncImages", () => {
    it("POSTs to the brand-scoped sync endpoint with file_ids array", async () => {
      mockOk({ data: [] });

      await productImageService.syncImages("acme", "prod-1", ["file-1", "file-2", "file-3"]);

      const [url, opts] = mockFetch.mock.calls[0];
      expect(url).toContain("/api/v1/hq/acme/products/prod-1/images/sync");
      expect(opts.method).toBe("POST");

      const body = JSON.parse(opts.body);
      expect(body).toEqual({ file_ids: ["file-1", "file-2", "file-3"] });
    });

    it("preserves the order of file_ids in the request", async () => {
      mockOk({ data: [] });

      // Reordered: 3rd item moved to front
      await productImageService.syncImages("acme", "prod-1", ["file-3", "file-1", "file-2"]);

      const body = JSON.parse(mockFetch.mock.calls[0][1].body);
      expect(body.file_ids[0]).toBe("file-3");
      expect(body.file_ids[1]).toBe("file-1");
      expect(body.file_ids[2]).toBe("file-2");
    });

    it("accepts an empty array (clears the gallery)", async () => {
      mockOk({ data: [] });

      await productImageService.syncImages("acme", "prod-1", []);

      const body = JSON.parse(mockFetch.mock.calls[0][1].body);
      expect(body).toEqual({ file_ids: [] });
    });

    it("returns the authoritative gallery from the backend response", async () => {
      const gallery = [
        { id: "file-1", url: "u1", sort_order: 0, original_name: "a.jpg", is_permanent: true },
        { id: "file-2", url: "u2", sort_order: 1, original_name: "b.jpg", is_permanent: true },
      ];
      mockOk({ data: gallery });

      const result = await productImageService.syncImages("acme", "prod-1", ["file-1", "file-2"]);

      expect(result.data).toEqual(gallery);
    });
  });
});
