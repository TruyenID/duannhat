"use client";

import { Skeleton } from "@godxjp/ui";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@godxjp/ui";

export interface DataTableSkeletonProps {
  /** Number of skeleton rows to render. Default: 8 */
  rows?: number;
  /** Number of columns. Default: 6 */
  columns?: number;
}

export function DataTableSkeleton({ rows = 8, columns = 6 }: DataTableSkeletonProps) {
  return (
    <div data-slot="data-table-skeleton" className="rounded-md border">
      <Table>
        <TableHeader>
          <TableRow className="h-9">
            {Array.from({ length: columns }).map((_, i) => (
              <TableHead key={i} className="h-9 px-3">
                <Skeleton className="h-3.5 w-16" />
              </TableHead>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody>
          {Array.from({ length: rows }).map((_, rowIdx) => (
            <TableRow key={rowIdx} className="h-9">
              {Array.from({ length: columns }).map((_, colIdx) => (
                <TableCell key={colIdx} className="px-3 py-1.5">
                  <Skeleton
                    className={`h-3.5 ${
                      colIdx === 0
                        ? "w-4"
                        : colIdx === 1
                          ? "w-8"
                          : colIdx === columns - 1
                            ? "w-6"
                            : "w-20"
                    }`}
                  />
                </TableCell>
              ))}
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
