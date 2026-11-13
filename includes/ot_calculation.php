<?php

function getOtTypeLabels(): array
{
    return [
        'workday_post' => 'วันทำงานปกติ (หลังเลิก)',
        'workday_pre'  => 'วันทำงานปกติ (ก่อนเวลา)',
        'holiday'      => 'วันหยุด',
    ];
}

function calculateOtCompensation(int $roundedHours): float
{
    if ($roundedHours < 1) {
        return 0.0;
    }
    if ($roundedHours <= 2) {
        return 325.0;
    }
    if ($roundedHours <= 4) {
        return 650.0;
    }
    if ($roundedHours <= 6) {
        return 975.0;
    }
    if ($roundedHours <= 8) {
        return 1300.0;
    }

    return min($roundedHours * 162.5, 2600.0);
}

function calculateLunchOverlapMinutes(int $startMinutes, int $endAbsoluteMinutes): int
{
    $totalOverlap = 0;
    // ตรวจสอบช่วงพักเที่ยง 12:00-13:00 ของวันแรก และวันถัดไป (กรณีข้ามเที่ยงคืน)
    $lunchWindows = [
        [12 * 60, 13 * 60],
        [36 * 60, 37 * 60],
    ];

    foreach ($lunchWindows as [$lunchStart, $lunchEnd]) {
        $overlap = min($endAbsoluteMinutes, $lunchEnd) - max($startMinutes, $lunchStart);
        if ($overlap > 0) {
            $totalOverlap += $overlap;
        }
    }

    return $totalOverlap;
}

function calculateOtDetails(string $startTime, string $endTime, string $otType): array
{
    $labels = getOtTypeLabels();
    $start  = DateTimeImmutable::createFromFormat('!H:i', $startTime);
    $end    = DateTimeImmutable::createFromFormat('!H:i', $endTime);

    if (! isset($labels[$otType]) || ! $start || ! $end ||
        $start->format('H:i') !== $startTime || $end->format('H:i') !== $endTime) {
        throw new InvalidArgumentException('กรุณาตรวจสอบประเภทและเวลา OT');
    }

    $startMinutes = ((int) $start->format('H')) * 60 + (int) $start->format('i');
    $endMinutes   = ((int) $end->format('H')) * 60 + (int) $end->format('i');

    if ($startMinutes === $endMinutes) {
        throw new InvalidArgumentException('เวลาเริ่มและเวลาสิ้นสุดต้องไม่เท่ากัน');
    }

    if ($otType === 'workday_post' && $startMinutes < 17 * 60 + 30) {
        throw new InvalidArgumentException('OT หลังเลิกงานต้องเริ่มตั้งแต่ 17:30 น.');
    }

    if ($otType === 'workday_pre') {
        if ($endMinutes > 8 * 60) {
            throw new InvalidArgumentException('OT ก่อนเวลาทำงานต้องสิ้นสุดไม่เกิน 08:00 น.');
        }
        // ป้องกันการกรอกเช่น 09:00 - 07:00 แล้วบวกข้ามวันเป็น 22 ชม.
        if ($startMinutes >= $endMinutes) {
            throw new InvalidArgumentException('OT ก่อนเวลาทำงานต้องเริ่มก่อนเวลาสิ้นสุดในวันเดียวกัน');
        }
    }

    $rawMinutes = $endMinutes - $startMinutes;
    if ($rawMinutes < 0) {
        $rawMinutes += 24 * 60;
    }

    $netMinutes   = $rawMinutes;
    $lunchOverlap = ($otType === 'holiday')
        ? calculateLunchOverlapMinutes($startMinutes, $startMinutes + $rawMinutes)
        : 0;
    $lunchDeducted = $lunchOverlap > 0;

    if ($lunchDeducted) {
        // หักตามเวลาที่ทับซ้อนกับ 12:00-13:00 จริง (สูงสุด 60 นาทีต่อวัน)
        $netMinutes = max(0, $netMinutes - $lunchOverlap);
    }

    $roundedHours = intdiv($netMinutes, 60) + (($netMinutes % 60) >= 30 ? 1 : 0);

    return [
        'raw_hours'      => round($rawMinutes / 60, 2),
        'net_hours'      => round($netMinutes / 60, 2),
        'hours'          => $roundedHours,
        'lunch_deducted' => $lunchDeducted ? 1 : 0,
        'total_pay'      => calculateOtCompensation($roundedHours),
    ];
}
