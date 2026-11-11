<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $localNow = now('Asia/Manila');
        $firstName = Str::of(request()->user()?->name ?? 'Francis')
            ->trim()
            ->before(' ')
            ->value();

        $greeting = match (true) {
            $localNow->hour < 12 => 'Good morning',
            $localNow->hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };

        return view('dashboard', [
            'hero' => [
                'greeting' => $greeting,
                'firstName' => $firstName ?: 'Francis',
                'date' => $localNow->format('D, M j, Y'),
                'dateTime' => $localNow->toDateString(),
            ],
            'stats' => [
                ['label' => 'Total Validations', 'value' => 286, 'trend' => '12%', 'direction' => 'up', 'comparison' => '+31 from last month', 'tone' => 'green', 'icon' => 'document'],
                ['label' => 'For Evaluation', 'value' => 48, 'trend' => '18%', 'direction' => 'down', 'comparison' => '-11 from last month', 'tone' => 'blue', 'icon' => 'users'],
                ['label' => 'Qualified Scholars', 'value' => 192, 'trend' => '15%', 'direction' => 'up', 'comparison' => '+25 from last month', 'tone' => 'amber', 'icon' => 'graduation-cap'],
                ['label' => 'Active Scholars', 'value' => 120, 'trend' => '6%', 'direction' => 'up', 'comparison' => '+7 from last year', 'tone' => 'purple', 'icon' => 'user-group'],
            ],
            'applications' => [18, 31, 29, 36, 44, 57, 50, 70, 76, 60, 51, 45],
            'qualified' => [10, 15, 17, 21, 28, 23, 25, 44, 52, 39, 33, 24],
            'statuses' => [
                ['label' => 'Qualified', 'count' => 192, 'percent' => 67, 'tone' => 'green'],
                ['label' => 'Under Review', 'count' => 48, 'percent' => 17, 'tone' => 'blue'],
                ['label' => 'Not Qualified', 'count' => 32, 'percent' => 11, 'tone' => 'amber'],
                ['label' => 'Incomplete', 'count' => 14, 'percent' => 5, 'tone' => 'gray'],
            ],
            'recentApplications' => [
                ['id' => '001', 'initials' => 'AD', 'name' => 'Anne Dela Cruz', 'program' => 'College', 'status' => 'For Evaluation', 'submitted' => 'Sep 15, 2025'],
                ['id' => '002', 'initials' => 'MS', 'name' => 'Mark Santos', 'program' => 'Senior High', 'status' => 'Complete', 'submitted' => 'Sep 14, 2025'],
                ['id' => '003', 'initials' => 'BR', 'name' => 'Bea Reyes', 'program' => 'College', 'status' => 'For Evaluation', 'submitted' => 'Sep 14, 2025'],
                ['id' => '004', 'initials' => 'JT', 'name' => 'Jiro Tan', 'program' => 'Technical-Vocational', 'status' => 'Incomplete', 'submitted' => 'Sep 13, 2025'],
                ['id' => '005', 'initials' => 'LM', 'name' => 'Liza Mendoza', 'program' => 'College', 'status' => 'Qualified', 'submitted' => 'Sep 12, 2025'],
            ],
            'programs' => [
                ['name' => 'College', 'count' => 68, 'percent' => 42, 'shade' => '100'],
                ['name' => 'Senior High School', 'count' => 32, 'percent' => 20, 'shade' => '80'],
                ['name' => 'Technical-Vocational', 'count' => 14, 'percent' => 9, 'shade' => '60'],
                ['name' => 'Graduate Studies', 'count' => 6, 'percent' => 4, 'shade' => '40'],
            ],
            'quickLinks' => [
                ['label' => 'Manage Scholars', 'icon' => 'user-group', 'href' => '#scholars-by-program'],
                ['label' => 'Evaluation Queue', 'icon' => 'clipboard', 'href' => '#recent-applications'],
                ['label' => 'Scholarship Programs', 'icon' => 'graduation-cap', 'href' => '#scholars-by-program'],
                ['label' => 'Reports', 'icon' => 'chart', 'href' => '#applications-trend'],
                ['label' => 'Announcements', 'icon' => 'megaphone', 'href' => '#announcements'],
                ['label' => 'Settings', 'icon' => 'settings', 'href' => route('profile.edit')],
            ],
        ]);
    }
}
