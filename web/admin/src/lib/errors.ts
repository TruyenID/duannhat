import { toast } from "sonner";
import { type UseFormSetError, type FieldValues, type Path } from "react-hook-form";
import { type ResponseError, type FieldError } from "@/types/base";

/* ================= ERROR CLASSES ================= */

export class HttpError extends Error {
  payload: ResponseError;

  constructor(payload: ResponseError) {
    super(payload.message);
    this.name = "HttpError";
    this.payload = payload;
  }
}

export class EntityError extends HttpError {
  constructor(payload: ResponseError) {
    super(payload);
    this.name = "EntityError";
  }
}

/* ================= HANDLE ERROR ================= */

interface HandleErrorParams<T extends FieldValues = FieldValues> {
  error: unknown;
  setError?: UseFormSetError<T>;
}

export const handleErrorApi = <T extends FieldValues = FieldValues>({
  error,
  setError,
}: HandleErrorParams<T>) => {
  if (error instanceof EntityError) {
    if (!setError) {
      return;
    }

    // map errors với setError
    const errors = error.payload.errors;
    if (errors && errors.length > 0) {
      errors.forEach((err: FieldError) => {
        setError(err.field as Path<T>, {
          type: "server",
          message: err.message,
        });
      });
    }
    return;
  }

  if (error instanceof HttpError) {
    toast.error(error.message);
    return;
  }

  toast.error("Có lỗi không xác định xảy ra");
};
