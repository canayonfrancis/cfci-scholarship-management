<?php

namespace App\Http\Controllers;

use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\DashboardAnalytics;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardAnalytics $analytics)
    {
        $now = now('Asia/Manila');
        $hero = ['greeting' => $now->hour < 12 ? 'Good morning' : ($now->hour < 18 ? 'Good afternoon' : 'Good evening'), 'firstName' => Str::of($request->user()->name)->trim()->before(' ')->value(), 'date' => $now->format('D, M j, Y'), 'dateTime' => $now->toDateString()];
        $canReport = $request->user()->hasAnyRole(User::STAFF_ROLES);
        if (! $canReport) {
            return view('dashboard', compact('hero', 'canReport'));
        }
        $canFinancial = $request->user()->can('financial-view');
        $data = $analytics->build([], $canFinancial);
        $metrics = $data['metrics'];
        $stats = [];
        foreach ([['total_scholars', 'Total scholars', 'green', 'users', 'Distinct registered identities, including archived'], ['active_awards', 'Active scholarship awards', 'purple', 'user-group', 'Currently active awards for active scholars'], ['pending_evaluations', 'Pending evaluations', 'blue', 'document', 'Latest semester checks needing evaluation or reassessment'], ['school_eligible', 'School-eligible scholars', 'amber', 'graduation-cap', 'Distinct active students with a current qualified decision']] as [$key, $label, $tone, $icon, $comparison]) {
            $stats[] = compact('key', 'label', 'tone', 'icon', 'comparison') + ['value' => $metrics[$key], 'money' => false];
        }
        $additionalStats = [['label' => 'Pending external approvals', 'value' => $metrics['pending_external'], 'tone' => 'blue', 'icon' => 'clipboard', 'comparison' => 'Semester decisions awaiting verification', 'money' => false]];
        if ($canFinancial) {
            foreach ([['billing_total', 'Billing totals', 'Submitted original amounts; not receipts'], ['school_funding', 'Recorded school funding', 'Verified posted receipts, less reversals'], ['student_payouts', 'Documented student payouts', 'Posted student net: school + direct, less reversals'], ['incomplete_liquidations', 'Incomplete liquidation records', 'Approved batches not fully reconciled and accepted']] as [$key, $label, $comparison]) {
                $additionalStats[] = ['label' => $label, 'value' => $metrics[$key], 'comparison' => $comparison, 'tone' => 'green', 'icon' => 'document', 'money' => $key !== 'incomplete_liquidations'];
            }
        }
        $counts = $data['evaluations']->countBy('state');
        $statuses = [];
        $segments = [];
        $position = 0;
        foreach ([['School eligible', 'green', '#278263'], ['Pending review', 'blue', '#4a9ddb'], ['School ineligible', 'amber', '#a80e0e'], ['Not evaluated', 'gray', '#a7b0be']] as [$label, $tone, $color]) {
            $count = $counts[$label] ?? 0;
            $percent = $data['evaluations']->count() ? $count * 100 / $data['evaluations']->count() : 0;
            $statuses[] = compact('label', 'tone', 'count') + ['percent' => round($percent, 1)];
            $segments[] = $color.' '.round($position, 3).'% '.round($position + $percent, 3).'%';
            $position += $percent;
        }
        $donutStyle = $position ? 'conic-gradient('.implode(', ', $segments).')' : '#e6e8ec';
        $months = [];
        $applications = [];
        $qualified = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = $now->copy()->startOfMonth()->subMonths($i);
            $key = $month->format('Y-m');
            $months[] = $month->format('M Y');
            $cohort = $data['evaluations']->filter(fn ($r) => $r['application']->latestEvaluation?->completed_at?->timezone('Asia/Manila')->format('Y-m') === $key);
            $applications[] = $cohort->count();
            $qualified[] = $cohort->where('state', 'School eligible')->count();
        }
        $chartMax = max(4, (int) (ceil(max([...$applications, ...$qualified, 1]) / 4) * 4));
        $recentApplications = $data['evaluations']->sortByDesc('recorded_at')->take(5)->map(fn ($r) => ['id' => $r['student_number'], 'initials' => Str::of($r['name'])->explode(' ')->filter()->take(2)->map(fn ($p) => Str::substr($p, 0, 1))->implode(''), 'name' => $r['name'], 'program' => $r['program'], 'status' => $r['state'], 'submitted' => $r['recorded_at']?->timezone('Asia/Manila')->format('M j, Y') ?? 'Not recorded', 'url' => route('evaluations.show', $r['id'])]);
        $programs = $data['programs'];
        $quickLinks = [['label' => 'Manage Scholars', 'icon' => 'user-group', 'href' => route('scholars.index')], ['label' => 'Scholarship Programs', 'icon' => 'graduation-cap', 'href' => route('scholarship-programs.index')], ['label' => 'Settings', 'icon' => 'settings', 'href' => route('profile.edit')]];
        if ($request->user()->can('viewAny', ScholarshipApplication::class)) {
            $quickLinks[] = ['label' => 'Evaluation Queue', 'icon' => 'clipboard', 'href' => route('evaluations.index')];
        }
        if ($canFinancial) {
            $quickLinks[] = ['label' => 'Payments & liquidation', 'icon' => 'document', 'href' => route('financial.index')];
        }

        return view('dashboard', compact('hero', 'canReport', 'metrics', 'stats', 'additionalStats', 'statuses', 'donutStyle', 'months', 'applications', 'qualified', 'chartMax', 'recentApplications', 'programs', 'quickLinks'));
    }
}
