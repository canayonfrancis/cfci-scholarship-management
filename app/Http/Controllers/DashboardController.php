<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DashboardAnalytics;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardAnalytics $analytics)
    {
        if ($request->user()->isStudentOnly()) {
            return to_route('student.profile');
        }

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

        return view('dashboard', compact('hero', 'canReport', 'stats', 'additionalStats'));
    }
}
