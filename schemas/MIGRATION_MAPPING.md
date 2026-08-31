# Schema Migration Mapping

## Overview

Tài liệu này ghi chú sự thay đổi từ hệ thống schema cũ sang schema mới theo docs thiết kế (`docs/time-attendance-system-design.md`).

---

## Schema Mapping

### Đã xóa (Removed)

| Old Schema | New Schema | Notes |
|------------|------------|-------|
| `Attendance/Attendance.yaml` | `TimeManagement/TimeEntry.yaml` | Đổi tên và cấu trúc theo docs 4.7 |
| `Attendance/AttendanceDetail.yaml` | `TimeManagement/TimeLog.yaml` | Đổi tên và cấu trúc theo docs 4.5 |
| `Attendance/AttendanceWorkplace.yaml` | **Removed** | Dùng `Location` thay thế |
| `Enum/AttendanceStatus.yaml` | `Enum/PunchType.yaml` | Đổi concept từ status sang type |

### Đã tạo mới (Created)

| Schema | Based on Docs | Purpose |
|--------|---------------|---------|
| `TimeManagement/TimeLog.yaml` | docs 4.5 | Raw punch data |
| `TimeManagement/TimeLogPair.yaml` | docs 4.6 | Ghép cặp IN/OUT |
| `TimeManagement/TimeEntry.yaml` | docs 4.7 | Kết quả tính toán theo ngày |
| `Enum/PunchType.yaml` | docs 4.5 | Loại punch: In/Out/BreakStart/BreakEnd |
| `Enum/PairType.yaml` | docs 4.6 | Loại cặp: Work/Break/Outing |
| `Enum/PunchSource.yaml` | docs 4.5 | Nguồn punch: Terminal/Mobile/Web/Manual |

---

## Field Mapping

### Attendance → TimeEntry

| Old Field (Attendance) | New Field (TimeEntry) | Notes |
|------------------------|----------------------|-------|
| `date` | `date` | Giữ nguyên |
| `start_time` | `actual_start` | Đổi tên, dùng TIME thay vì TIMESTAMP |
| `end_time` | `actual_end` | Đổi tên, dùng TIME thay vì TIMESTAMP |
| `total_work_time` | `actual_minutes` | Đổi từ Float (giờ) sang Int (phút) |
| `total_night_work_time` | `night_minutes` | Đổi sang phút |
| `total_break_time` | `break_minutes` | Đổi sang phút |
| `approval_status` | `status` | Giữ nguyên enum ApprovalStatus |
| `attendance_status` | **Removed** | Dùng TimeLog.type thay thế |
| `attendance_workplace` | `location` | Dùng Location đầy đủ |
| `details` | **Via query** | TimeLog không có FK trực tiếp đến TimeEntry. Query qua employee_id + date |
| **New** | `scheduled_start` | Giờ bắt đầu theo lịch |
| **New** | `scheduled_end` | Giờ kết thúc theo lịch |
| **New** | `scheduled_minutes` | Số phút theo lịch |
| **New** | `regular_minutes` | Giờ thường (phút) |
| **New** | `ot_minutes` | OT ngày thường (phút) |
| **New** | `ot_weekend_minutes` | OT cuối tuần (phút) |
| **New** | `ot_holiday_minutes` | OT ngày lễ (phút) |
| **New** | `holiday_minutes` | Giờ ngày lễ (phút) |
| **New** | `late_minutes` | Đi muộn (phút) |
| **New** | `early_leave_minutes` | Về sớm (phút) |
| **New** | `contract` | Contract active tại ngày đó |
| **New** | `work_type` | Loại công việc |
| **New** | `cost_center` | Trung tâm chi phí |
| **New** | `approved_by` | Người phê duyệt |
| **New** | `approved_at` | Thời điểm phê duyệt |

### AttendanceDetail → TimeLog

| Old Field (AttendanceDetail) | New Field (TimeLog) | Notes |
|-----------------------------|---------------------|-------|
| `recorded_at` | `timestamp` | Đổi tên |
| `attendance_status` | `type` (PunchType) | Đổi enum |
| `location_latitude` | `latitude` | Đổi từ Float sang Decimal(10,8) |
| `location_longitude` | `longitude` | Đổi từ Float sang Decimal(11,8) |
| `location_address` | `location_address` | Giữ nguyên |
| `proof_image_path` | `photo_url` | Đổi tên |
| `note` | `note` | Giữ nguyên |
| `attendance_workplace` | `location` | Dùng Location đầy đủ |
| `rounded_recorded_at` | **Removed** | Tính tại TimeEntry |
| **New** | `location_accuracy` | Độ chính xác GPS (meters) |
| **New** | `is_within_geofence` | Kết quả kiểm tra geofence |
| **New** | `source` (PunchSource) | Nguồn punch |
| **New** | `work_type` | Loại công việc |
| **New** | `cost_center` | Trung tâm chi phí |
| **New** | `device` | Thiết bị chấm công |

### AttendanceWorkplace → Location

| Old (AttendanceWorkplace) | New (Location) | Notes |
|--------------------------|----------------|-------|
| `name` | `name` | Giữ nguyên |
| `organization_id` | **Via Branch** | Qua BranchCache |
| `branch_id` | `branch` | Association |
| `description` | **Removed** | Không cần |
| **New** | `code` | Mã định danh |
| **New** | `type` | LocationType enum |
| **New** | `latitude` | GPS |
| **New** | `longitude` | GPS |
| **New** | `radius_meters` | Geofence |
| **New** | `timezone` | Múi giờ |
| **New** | `is_virtual` | Địa điểm ảo |
| **New** | `status` | LocationStatus enum |

---

## Enum Mapping

### AttendanceStatus → PunchType

| Old (AttendanceStatus) | New (PunchType) | Notes |
|------------------------|-----------------|-------|
| `NotYetWorking` | **Removed** | Không cần - trạng thái mặc định |
| `Working` | `In` | Check-in |
| `Break` | `BreakStart` | Bắt đầu nghỉ |
| `BreakEnd` | `BreakEnd` | Kết thúc nghỉ |
| `Outing` | `OutingStart` | Ra ngoài |
| `OutingEnd` | `OutingEnd` | Về |
| `ClockOut` | `Out` | Check-out |

---

## Architecture Note: Relationships

Theo docs, **TimeEntry, TimeLogPair, TimeLog** KHÔNG có FK trực tiếp với nhau:

```
┌─────────────────────────────────────────────────────────────────┐
│  TimeLog (raw punch data)                                       │
│    - employee_id (FK → Employee)                                │
│    - timestamp (DATETIME)                                       │
│    - NO time_entry_id!                                          │
├─────────────────────────────────────────────────────────────────┤
│  TimeLogPair (paired punches)                                   │
│    - employee_id (FK → Employee)                                │
│    - date (DATE)                                                │
│    - start_log_id (FK → TimeLog)                                │
│    - end_log_id (FK → TimeLog, nullable)                        │
│    - NO time_entry_id!                                          │
├─────────────────────────────────────────────────────────────────┤
│  TimeEntry (daily aggregated result)                            │
│    - employee_id (FK → Employee)                                │
│    - date (DATE)                                                │
│    - calculated fields (minutes, OT, night, etc.)               │
└─────────────────────────────────────────────────────────────────┘

Quan hệ qua: employee_id + date
```

**Để query TimeLogs của TimeEntry:**
```php
TimeLog::where('employee_id', $entry->employee_id)
       ->whereDate('timestamp', $entry->date)
       ->get();
```

**Để query TimeLogPairs của TimeEntry:**
```php
TimeLogPair::where('employee_id', $entry->employee_id)
           ->where('date', $entry->date)
           ->get();
```

---

## Database Migration Notes

### Tables to Drop
```sql
DROP TABLE IF EXISTS attendance_details;
DROP TABLE IF EXISTS attendances;
DROP TABLE IF EXISTS attendance_workplaces;
```

### Tables to Create
```sql
-- Theo thứ tự dependency
CREATE TABLE time_logs (...);
CREATE TABLE time_log_pairs (...);
CREATE TABLE time_entries (...);
```

### Data Migration
Nếu cần migrate dữ liệu cũ, cần script chuyển đổi:
1. `attendances` → `time_entries`
2. `attendance_details` → `time_logs`
3. `attendance_workplaces` → `locations` (merge vào Location có sẵn)

---

## API Changes

### Endpoints to Update

| Old Endpoint | New Endpoint |
|--------------|--------------|
| `GET /api/attendances` | `GET /api/time-entries` |
| `GET /api/attendances/{id}` | `GET /api/time-entries/{id}` |
| `POST /api/attendances/{id}/punch` | `POST /api/time-logs` |
| `GET /api/attendance-details` | `GET /api/time-logs` |

---

## Version History

| Date | Change |
|------|--------|
| 2026-01-26 | Initial migration from Attendance to TimeManagement |
