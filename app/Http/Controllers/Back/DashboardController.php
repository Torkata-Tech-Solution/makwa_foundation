<?php

namespace App\Http\Controllers\Back;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\News;
use App\Models\NewsComment;
use App\Models\NewsViewer;
use App\Models\Visitor;
use Illuminate\Support\Facades\DB;
use App\Models\Announcement;
use App\Models\Event;
use App\Models\Finance;
use App\Models\Payment;
use App\Models\FinanceYear;
use App\Models\Journal;
use App\Models\Issue;
use App\Models\Submission;
use App\Models\PaymentInvoice;
use App\Models\WaitingSubmission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function index()
    {
        $data = [
            'title' => 'Dashboard',
            'breadcrumb' => [
                [
                    'name' => 'Dashboard',
                    'link' => route('back.dashboard')
                ],
            ],


        ];
        return view('back.pages.dashboard.index', $data);
    }

    public function stats()
    {
        $data = [
            'journals' => \App\Models\Journal::count(),
            'submissions' => \App\Models\Submission::count(),
            'events' => \App\Models\Event::count(),
            'users' => \App\Models\User::count(),
        ];
        return response()->json($data);
    }

    public function visistorStat()
    {


        $data = cache()->remember('visitor_stats', 60, function () {
            return [
                'visitor_monthly' => Visitor::select(DB::raw('Date(created_at) as date'), DB::raw('count(*) as total'))
                    ->orderBy('date', 'desc')
                    ->limit(30)
                    ->groupBy('date')
                    ->get(),
                'visitor_platfrom' => Visitor::select('platform', DB::raw('count(*) as total'))
                    ->groupBy('platform')
                    ->get(),
                'visitor_browser' => Visitor::select('browser', DB::raw('count(*) as total'))
                    ->groupBy('browser')
                    ->get(),
                'visitor_country' => Visitor::select('country', DB::raw('count(*) as total'))
                    ->whereNotNull('country')
                    ->groupBy('country')
                    ->orderBy('total', 'desc')
                    ->get()
                    ->map(function ($item) {
                        $countryName = $item->country;

                        $hash = substr(md5($countryName), 0, 6);
                        $item->color = "#{$hash}";
                        return $item;
                    }),
            ];
        });
        return response()->json($data);
    }

    public function news()
    {
        $data = [
            'title' => 'Dashboard Berita',
            'menu' => 'dashboard',
            'sub_menu' => '',
            'berita_count' => News::count(),
            'news_popular' => News::with('comments')->withCount('viewers')->orderBy('viewers_count', 'desc')->limit(5)->get(),
            'news_new' => News::with(['comments', 'viewers'])->latest()->limit(5)->get(),
            'news_writer' => news::select(
                DB::raw('count(*) as total'),
                'news.user_id',
            )
                ->groupBy('news.user_id')
                ->orderBy('total', 'desc')
                ->limit(5)
                ->get(),
        ];
        return view('back.pages.dashboard.news', $data);
    }

    public function stat()
    {


        $data = [
            'news_viewer_monthly' => NewsViewer::select(DB::raw('Date(created_at) as date'), DB::raw('count(*) as total'))
                ->limit(30)
                ->groupBy('date')
                ->get(),
            'news_viewer_platfrom' => NewsViewer::select('platform', DB::raw('count(*) as total'))
                ->groupBy('platform')
                ->get(),
            'news_viewer_browser' => NewsViewer::select('browser', DB::raw('count(*) as total'))
                ->groupBy('browser')
                ->get(),

        ];
        return response()->json($data);
    }

    public function cashFlow()
    {
        $data = [
            'title' => 'Dashboard Cashflow',
            'breadcrumbs' => [
                [
                    'name' => 'Dashboard',
                    'link' => route('back.dashboard')
                ],
                [
                    'name' => 'Cashflow',
                    'link' => route('back.dashboard.cashflow')
                ]
            ]
        ];
        return view('back.pages.dashboard.cashflow', $data);
    }

    public function cashflowStat()
    {
        try {
            $data = cache()->remember('cashflow_stats', 60, function () {
                // Get current finance year
                $financeYear = FinanceYear::latest()->first();
                $startDate = $financeYear ? $financeYear->start_date : now()->startOfYear()->toDateString();
                $endDate = $financeYear && $financeYear->end_date ? $financeYear->end_date : now()->addDay()->toDateString();

                // Monthly cashflow data
                $monthlyData = Finance::select(
                    DB::raw('DATE(date) as date'),
                    DB::raw('SUM(CASE WHEN type = "income" THEN amount ELSE 0 END) as income'),
                    DB::raw('SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END) as expense')
                )
                ->where('date', '>=', $startDate)
                ->where('date', '<=', $endDate)
                ->groupBy(DB::raw('DATE(date)'))
                ->orderBy('date', 'desc')
                ->limit(30)
                ->get();

                // Payment income data
                $paymentIncome = Payment::with(['paymentInvoice'])
                    ->where('created_at', '>=', $startDate)
                    ->where('created_at', '<=', $endDate)
                    ->where('payment_status', 'accepted')
                    ->get()
                    ->groupBy(function($payment) {
                        return $payment->created_at->format('Y-m-d');
                    })
                    ->map(function($payments) {
                        return $payments->sum(function($payment) {
                            return $payment->paymentInvoice->payment_amount ?? 0;
                        });
                    });

                // Merge and process monthly data
                $mergedMonthly = $monthlyData->map(function ($item) use ($paymentIncome) {
                    $paymentForDate = $paymentIncome->get($item->date, 0);
                    $totalIncome = (int)($item->income + $paymentForDate);
                    $expense = (int)$item->expense;

                    return [
                        'date' => $item->date,
                        'income' => $totalIncome,
                        'expense' => $expense,
                        'balance' => $totalIncome - $expense
                    ];
                });

                // Transaction type distribution
                $transactionTypes = Finance::select('type', DB::raw('count(*) as count'), DB::raw('sum(amount) as total'))
                    ->where('date', '>=', $startDate)
                    ->where('date', '<=', $endDate)
                    ->groupBy('type')
                    ->get();

                // Finance Years overview
                $financeYears = FinanceYear::orderBy('start_date', 'desc')
                    ->limit(5)
                    ->get();

                if ($financeYears->isEmpty()) {
                    // If no finance years exist, create a default one for current year
                    $financeYears = collect([[
                        'name' => 'Current Year (' . now()->year . ')',
                        'income' => 0,
                        'outcome' => 0,
                        'balance' => 0,
                        'start_date' => now()->startOfYear()->toDateString(),
                        'end_date' => now()->endOfYear()->toDateString(),
                        'is_active' => true
                    ]]);
                } else {
                    $financeYears = $financeYears->map(function ($year) {
                        $startDate = $year->start_date;
                        $endDate = $year->end_date ?? now()->addDay()->toDateString();

                        // Calculate income for this finance year
                        $income = Finance::where('type', 'income')
                            ->where('date', '>=', $startDate)
                            ->where('date', '<=', $endDate)
                            ->sum('amount');

                        // Calculate payment income for this finance year
                        $paymentIncome = Payment::with(['paymentInvoice'])
                            ->where('created_at', '>=', $startDate)
                            ->where('created_at', '<=', $endDate)
                            ->where('payment_status', 'accepted')
                            ->get()
                            ->sum(function ($payment) {
                                return $payment->paymentInvoice->payment_amount ?? 0;
                            });

                        // Calculate outcome for this finance year
                        $outcome = Finance::where('type', 'expense')
                            ->where('date', '>=', $startDate)
                            ->where('date', '<=', $endDate)
                            ->sum('amount');

                        $totalIncome = $income + $paymentIncome;
                        $balance = $totalIncome - $outcome;

                        return [
                            'name' => $year->name,
                            'income' => (int)$totalIncome,
                            'outcome' => (int)$outcome,
                            'balance' => (int)$balance,
                            'start_date' => $year->start_date,
                            'end_date' => $year->end_date,
                            'is_active' => $year->is_active
                        ];
                    });
                }

                // Recent transactions
                $recentTransactions = Finance::where('date', '>=', $startDate)
                    ->where('date', '<=', $endDate)
                    ->orderBy('date', 'desc')
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
                    ->get();

                // Summary totals
                $totalIncome = Finance::where('type', 'income')
                    ->where('date', '>=', $startDate)
                    ->where('date', '<=', $endDate)
                    ->sum('amount');

                $totalPaymentIncome = Payment::with(['paymentInvoice'])
                    ->where('created_at', '>=', $startDate)
                    ->where('created_at', '<=', $endDate)
                    ->where('payment_status', 'accepted')
                    ->get()
                    ->sum(function ($payment) {
                        return $payment->paymentInvoice->payment_amount ?? 0;
                    });

                $totalExpense = Finance::where('type', 'expense')
                    ->where('date', '>=', $startDate)
                    ->where('date', '<=', $endDate)
                    ->sum('amount');

                // Calculate distribution based on finance year percentage
                $distributionPercentage = $financeYear ? $financeYear->distribution_percentage : 80;
                $totalGrossIncome = $totalIncome + $totalPaymentIncome;
                $distributionRumahJurnal = ($totalGrossIncome * $distributionPercentage) / 100;
                $distributionBLU = $totalGrossIncome - $distributionRumahJurnal;

                // Transaction counts
                $totalTransactionCount = Finance::where('date', '>=', $startDate)
                    ->where('date', '<=', $endDate)
                    ->count();

                $monthlyTransactionCount = Finance::where('date', '>=', now()->startOfMonth())
                    ->where('date', '<=', now()->endOfMonth())
                    ->count();

                return [
                    'monthly_cashflow' => $mergedMonthly->values()->toArray(),
                    'transaction_types' => $transactionTypes->toArray(),
                    'finance_years' => $financeYears->toArray(),
                    'recent_transactions' => $recentTransactions->toArray(),
                    'summary' => [
                        'total_income' => (int)($totalIncome + $totalPaymentIncome),
                        'total_expense' => (int)$totalExpense,
                        'total_balance' => (int)(($totalIncome + $totalPaymentIncome) - $totalExpense),
                        'finance_income' => (int)$totalIncome,
                        'payment_income' => (int)$totalPaymentIncome,
                        'transaction_count' => $totalTransactionCount,
                        'monthly_transactions' => $monthlyTransactionCount,
                        'distribution_percentage' => $distributionPercentage,
                        'distribution_rumah_jurnal' => (int)$distributionRumahJurnal,
                        'distribution_blu' => (int)$distributionBLU,
                        'total_gross_income' => (int)$totalGrossIncome
                    ]
                ];
            });

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to load cashflow data',
                'message' => $e->getMessage()
            ], 500);
        }
    }


    protected array $allowedDashboardJournalRoles = [
        'super-admin',
        'keuangan',
        'admin-ejournal',
        'admin-proceeding',
        'admin-student-research-hub',
        'editor',
        'editor-proceeding',
        'editor-student-research-hub',
    ];

    private function canUserAccessJournal($user, Journal $journal): bool
    {
        if (!$user || !$user->hasAnyRole($this->allowedDashboardJournalRoles)) {
            return false;
        }

        // super-admin and keuangan can open everything across all types
        if ($user->hasRole('super-admin') || $user->hasRole('keuangan')) {
            return true;
        }

        $type = $journal->type ?? 'journal';

        // admin-ejournal can open all journals, but only for type 'journal'
        if ($user->hasRole('admin-ejournal') && $type === 'journal') {
            return true;
        }

        // admin-proceeding can open all journals, but only for type 'proceeding'
        if ($user->hasRole('admin-proceeding') && $type === 'proceeding') {
            return true;
        }

        // admin-student-research-hub can open all journals, but only for type 'student_research_hub'
        if ($user->hasRole('admin-student-research-hub') && $type === 'student_research_hub') {
            return true;
        }

        // editor roles can only open journals based on permission url_path assigned to them
        if ($user->hasAnyRole(['editor', 'editor-proceeding', 'editor-student-research-hub'])) {
            if ($user->can($journal->url_path)) {
                return true;
            }
        }

        return false;
    }

    public function journal(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->hasAnyRole($this->allowedDashboardJournalRoles)) {
            abort(403, 'Anda tidak memiliki akses ke Dashboard Jurnal');
        }

        $controlPanel = $request->cookie('control_panel', 'journal');

        // Get journals accessible to this user based on their role and permissions
        $journals = Journal::orderBy('name')
            ->get()
            ->filter(function ($journal) use ($user) {
                return $this->canUserAccessJournal($user, $journal);
            })
            ->values();

        // Group journals by publication type
        $typeLabels = [
            'journal' => 'Jurnal / E-Journal',
            'proceeding' => 'Proceeding',
            'student_research_hub' => 'Student Research Hub',
        ];

        $groupedJournals = $journals->groupBy(function ($item) use ($typeLabels) {
            $type = $item->type ?? 'journal';
            return $typeLabels[$type] ?? ucfirst(str_replace('_', ' ', $type));
        });

        // Determine initially selected journal
        $selectedJournalId = $request->query('journal_id');
        if (!$selectedJournalId || !$journals->contains('id', $selectedJournalId)) {
            $matchingControlPanelJournal = $journals->firstWhere('type', $controlPanel);
            $selectedJournalId = $matchingControlPanelJournal ? $matchingControlPanelJournal->id : $journals->first()?->id;
        }

        $selectedIssueId = $request->query('issue_id');
        $selectedJournal = $journals->firstWhere('id', $selectedJournalId);
        $initialIssues = $selectedJournal
            ? Issue::where('journal_id', $selectedJournal->id)
                ->orderBy('year', 'desc')
                ->orderBy('volume', 'desc')
                ->orderBy('number', 'desc')
                ->get()
            : collect();

        $data = [
            'title' => 'Dashboard Jurnal',
            'breadcrumbs' => [
                [
                    'name' => 'Dashboard',
                    'link' => route('back.dashboard')
                ],
                [
                    'name' => 'Jurnal',
                    'link' => route('back.dashboard.journal')
                ]
            ],
            'journals' => $journals,
            'grouped_journals' => $groupedJournals,
            'selected_journal_id' => $selectedJournalId,
            'selected_issue_id' => $selectedIssueId,
            'initial_issues' => $initialIssues,
            'control_panel' => $controlPanel,
        ];

        return view('back.pages.dashboard.journal', $data);
    }

    public function journalStat(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user || !$user->hasAnyRole($this->allowedDashboardJournalRoles)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke Dashboard Jurnal',
                ], 403);
            }

            $controlPanel = $request->cookie('control_panel', 'journal');
            $journalId = $request->get('journal_id');
            $issueId = $request->get('issue_id');

            if ($journalId) {
                $journal = Journal::find($journalId);
            } else {
                $journals = Journal::orderBy('name')->get();
                $journal = $journals->firstWhere('type', $controlPanel);
                if (!$journal || !$this->canUserAccessJournal($user, $journal)) {
                    $journal = $journals->first(fn($j) => $this->canUserAccessJournal($user, $j));
                }
            }

            if (!$journal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jurnal tidak ditemukan atau Anda belum memiliki jurnal yang ditugaskan',
                ], 404);
            }

            // Check authorization specifically for this journal
            if (!$this->canUserAccessJournal($user, $journal)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke jurnal ini',
                ], 403);
            }

            // Retrieve all issues for options and count
            $allIssues = Issue::where('journal_id', $journal->id)
                ->orderBy('year', 'desc')
                ->orderBy('volume', 'desc')
                ->orderBy('number', 'desc')
                ->get();

            $issuesOptions = $allIssues->map(function ($iss) {
                $titleSuffix = (!empty($iss->title) && $iss->title !== '-') ? ' - ' . Str::limit($iss->title, 25) : '';
                return [
                    'id' => $iss->id,
                    'label' => 'Vol. ' . $iss->volume . ' No. ' . $iss->number . ' (' . ($iss->year ?? '-') . ')' . $titleSuffix,
                ];
            })->values();

            // Check if filtering by specific issue
            $selectedIssue = null;
            $isIssueFiltered = false;

            if ($issueId && $issueId !== 'all') {
                $selectedIssue = $allIssues->firstWhere('id', $issueId);
                if ($selectedIssue) {
                    $isIssueFiltered = true;
                    $issues = Issue::where('id', $selectedIssue->id)
                        ->with(['submissions.paymentInvoices.payments'])
                        ->get();
                } else {
                    $issues = Issue::where('journal_id', $journal->id)
                        ->with(['submissions.paymentInvoices.payments'])
                        ->orderBy('year', 'asc')
                        ->orderBy('volume', 'asc')
                        ->orderBy('number', 'asc')
                        ->get();
                }
            } else {
                $issues = Issue::where('journal_id', $journal->id)
                    ->with(['submissions.paymentInvoices.payments'])
                    ->orderBy('year', 'asc')
                    ->orderBy('volume', 'asc')
                    ->orderBy('number', 'asc')
                    ->get();
            }

            $totalSubmissions = 0;
            $publishedCount = 0;
            $unpublishedCount = 0;

            $lunasCount = 0;
            $lunasAmount = 0;

            $belumLunasCount = 0;
            $belumLunasPaid = 0;
            $belumLunasRemaining = 0;

            $belumBayarCount = 0;
            $belumBayarAmount = 0;

            $freeCount = 0;

            $issuesTableData = [];
            $issueChartCategories = [];
            $issueChartPublished = [];
            $issueChartUnpublished = [];

            $yearData = [];

            foreach ($issues as $issue) {
                $issueFee = (int)($issue->author_fee ?? ($journal->author_fee ?? 0));
                $issueArticlesCount = $issue->submissions->count();
                $issuePublished = 0;
                $issueUnpublished = 0;
                $issueLunas = 0;
                $issueBelumLunas = 0;
                $issueBelumBayar = 0;
                $issueFree = 0;
                $issueIncome = 0;

                foreach ($issue->submissions as $submission) {
                    $totalSubmissions++;

                    // Published status check
                    $isPublished = ($submission->status == 3)
                        || !empty($submission->urlPublished)
                        || Str::contains(strtolower($submission->status_label ?? ''), 'publish');

                    if ($isPublished) {
                        $publishedCount++;
                        $issuePublished++;
                    } else {
                        $unpublishedCount++;
                        $issueUnpublished++;
                    }

                    // Payment status check
                    $subFee = $issueFee;
                    $isFree = ($submission->free_charge == 1) || ($subFee <= 0);

                    if ($isFree) {
                        $freeCount++;
                        $issueFree++;
                    } else {
                        $paidInvoices = $submission->paymentInvoices->where('is_paid', 1);
                        $paidPercent = $paidInvoices->sum('payment_percent');
                        $paidAmount = $paidInvoices->sum('payment_amount');

                        if ($paidAmount == 0) {
                            $acceptedPayments = $submission->paymentInvoices->flatMap->payments->where('payment_status', 'accepted');
                            $paidAmount = $acceptedPayments->sum('payment_amount');
                        }

                        $isLunas = ($submission->payment_status === 'paid')
                            || ($paidPercent >= 100)
                            || ($subFee > 0 && $paidAmount >= $subFee);

                        if ($isLunas) {
                            $lunasCount++;
                            $issueLunas++;
                            $actualPaid = $paidAmount > 0 ? $paidAmount : $subFee;
                            $lunasAmount += $actualPaid;
                            $issueIncome += $actualPaid;
                        } elseif ($paidPercent > 0 || $paidAmount > 0) {
                            $belumLunasCount++;
                            $issueBelumLunas++;
                            $belumLunasPaid += $paidAmount;
                            $remaining = max(0, $subFee - $paidAmount);
                            $belumLunasRemaining += $remaining;
                            $issueIncome += $paidAmount;
                        } else {
                            $belumBayarCount++;
                            $issueBelumBayar++;
                            $belumBayarAmount += $subFee;
                        }
                    }
                }

                $year = $issue->year ?: ($issue->created_at ? $issue->created_at->format('Y') : 'Unknown');
                if (!isset($yearData[$year])) {
                    $yearData[$year] = [
                        'published' => 0,
                        'unpublished' => 0,
                    ];
                }
                $yearData[$year]['published'] += $issuePublished;
                $yearData[$year]['unpublished'] += $issueUnpublished;

                $issueLabel = 'Vol. ' . $issue->volume . ' No. ' . $issue->number . ($issue->year ? ' (' . $issue->year . ')' : '');
                $issueChartCategories[] = $issueLabel;
                $issueChartPublished[] = $issuePublished;
                $issueChartUnpublished[] = $issueUnpublished;

                $issuesTableData[] = [
                    'id' => $issue->id,
                    'volume' => $issue->volume,
                    'number' => $issue->number,
                    'year' => $issue->year,
                    'title' => $issue->title ?: '-',
                    'issue_label' => $issueLabel,
                    'author_fee' => (int)$issueFee,
                    'total_articles' => $issueArticlesCount,
                    'published_count' => $issuePublished,
                    'unpublished_count' => $issueUnpublished,
                    'lunas_count' => $issueLunas,
                    'belum_lunas_count' => $issueBelumLunas,
                    'belum_bayar_count' => $issueBelumBayar,
                    'free_count' => $issueFree,
                    'total_income' => (int)$issueIncome,
                    'action_url' => route('back.journal.article.index', [$journal->url_path, $issue->id]),
                ];
            }

            // Waiting submissions for this journal
            $waitingSubmissionsQuery = WaitingSubmission::where('target_journal_id', $journal->id);
            $totalWaiting = (clone $waitingSubmissionsQuery)->count();
            $waitingWaiting = (clone $waitingSubmissionsQuery)->where('status', 'waiting')->count();
            $waitingUnderReview = (clone $waitingSubmissionsQuery)->where('status', 'under_review')->count();
            $waitingAccepted = (clone $waitingSubmissionsQuery)->where('status', 'accepted')->count();

            // Total revenue received vs outstanding
            $totalPaidReceived = $lunasAmount + $belumLunasPaid;
            $totalOutstanding = $belumLunasRemaining + $belumBayarAmount;
            $totalPotentialRevenue = $totalPaidReceived + $totalOutstanding;

            // Sort year data chronologically
            ksort($yearData);
            $yearCategories = array_keys($yearData);
            $yearPublishedSeries = array_column(array_values($yearData), 'published');
            $yearUnpublishedSeries = array_column(array_values($yearData), 'unpublished');

            $currentIssueAuthorFee = $isIssueFiltered && $selectedIssue
                ? (int)($selectedIssue->author_fee ?? ($journal->author_fee ?? 0))
                : (int)($journal->author_fee ?? 0);

            $selectedIssueData = $selectedIssue ? [
                'id' => $selectedIssue->id,
                'label' => 'Vol. ' . $selectedIssue->volume . ' No. ' . $selectedIssue->number . ($selectedIssue->year ? ' (' . $selectedIssue->year . ')' : ''),
            ] : null;

            return response()->json([
                'success' => true,
                'journal' => [
                    'id' => $journal->id,
                    'name' => $journal->name,
                    'title' => $journal->title,
                    'url_path' => $journal->url_path,
                    'author_fee' => $currentIssueAuthorFee,
                    'journal_author_fee' => (int)($journal->author_fee ?? 0),
                    'total_issues' => $allIssues->count(),
                    'filtered_issues_count' => $issues->count(),
                    'selected_issue' => $selectedIssueData,
                ],
                'summary' => [
                    'is_issue_filtered' => $isIssueFiltered,
                    'total_submissions' => $totalSubmissions,
                    'total_published' => $publishedCount,
                    'total_unpublished' => $unpublishedCount,
                    'published_percentage' => $totalSubmissions > 0 ? round(($publishedCount / $totalSubmissions) * 100, 1) : 0,
                    'unpublished_percentage' => $totalSubmissions > 0 ? round(($unpublishedCount / $totalSubmissions) * 100, 1) : 0,

                    // Rekap data pembayaran
                    'lunas' => [
                        'count' => $lunasCount,
                        'amount' => (int)$lunasAmount,
                    ],
                    'belum_lunas' => [
                        'count' => $belumLunasCount,
                        'paid_amount' => (int)$belumLunasPaid,
                        'remaining_amount' => (int)$belumLunasRemaining,
                    ],
                    'belum_bayar' => [
                        'count' => $belumBayarCount,
                        'amount' => (int)$belumBayarAmount,
                    ],
                    'free' => [
                        'count' => $freeCount,
                    ],

                    // Finansial
                    'total_paid_received' => (int)$totalPaidReceived,
                    'total_outstanding' => (int)$totalOutstanding,
                    'total_potential_revenue' => (int)$totalPotentialRevenue,

                    // Naskah waiting
                    'waiting_submissions' => [
                        'total' => $totalWaiting,
                        'waiting' => $waitingWaiting,
                        'under_review' => $waitingUnderReview,
                        'accepted' => $waitingAccepted,
                    ],
                ],
                'charts' => [
                    'issue_chart' => [
                        'categories' => $issueChartCategories,
                        'published' => $issueChartPublished,
                        'unpublished' => $issueChartUnpublished,
                    ],
                    'year_chart' => [
                        'categories' => $yearCategories,
                        'published' => $yearPublishedSeries,
                        'unpublished' => $yearUnpublishedSeries,
                    ],
                    'payment_chart' => [
                        'labels' => ['Lunas', 'Belum Lunas', 'Belum Bayar', 'Free Charge'],
                        'series' => [$lunasCount, $belumLunasCount, $belumBayarCount, $freeCount],
                        'amounts' => [(int)$lunasAmount, (int)$belumLunasPaid, (int)$belumBayarAmount, 0],
                        'colors' => ['#50CD89', '#FFC700', '#F1416C', '#009EF7'],
                    ],
                    'article_status_chart' => [
                        'labels' => ['Published', 'Belum Publish', 'Naskah Menunggu'],
                        'series' => [$publishedCount, $unpublishedCount, $totalWaiting],
                        'colors' => ['#50CD89', '#FFC700', '#7239EA'],
                    ],
                ],
                'issues_table' => array_reverse($issuesTableData),
                'issues_options' => $issuesOptions,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Gagal memuat data statistik jurnal',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function journalSubmissions(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user || !$user->hasAnyRole($this->allowedDashboardJournalRoles)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke Dashboard Jurnal',
                ], 403);
            }

            $journalId = $request->get('journal_id');
            $issueId = $request->get('issue_id');
            $type = $request->get('type', 'belum_lunas');

            if ($journalId) {
                $journal = Journal::find($journalId);
            } else {
                $journals = Journal::orderBy('name')->get();
                $journal = $journals->first(fn($j) => $this->canUserAccessJournal($user, $j));
            }

            if (!$journal || !$this->canUserAccessJournal($user, $journal)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jurnal tidak ditemukan atau Anda tidak memiliki akses',
                ], 404);
            }

            $issuesQuery = Issue::where('journal_id', $journal->id)
                ->with(['submissions.paymentInvoices.payments']);

            $selectedIssue = null;
            if ($issueId && $issueId !== 'all') {
                $selectedIssue = Issue::where('journal_id', $journal->id)->find($issueId);
                if ($selectedIssue) {
                    $issuesQuery->where('id', $selectedIssue->id);
                }
            }

            $issues = $issuesQuery->orderBy('year', 'desc')
                ->orderBy('volume', 'desc')
                ->orderBy('number', 'desc')
                ->get();

            $matchedSubmissions = [];
            $totalFee = 0;
            $totalPaid = 0;
            $totalRemaining = 0;

            foreach ($issues as $issue) {
                $issueFee = (int)($issue->author_fee ?? ($journal->author_fee ?? 0));
                $issueLabel = 'Vol. ' . $issue->volume . ' No. ' . $issue->number . ($issue->year ? ' (' . $issue->year . ')' : '');

                foreach ($issue->submissions as $submission) {
                    $subFee = $issueFee;
                    $isFree = ($submission->free_charge == 1) || ($subFee <= 0);

                    if ($isFree) {
                        continue;
                    }

                    $paidInvoices = $submission->paymentInvoices->where('is_paid', 1);
                    $paidPercent = (int)$paidInvoices->sum('payment_percent');
                    $paidAmount = (int)$paidInvoices->sum('payment_amount');

                    if ($paidAmount == 0) {
                        $acceptedPayments = $submission->paymentInvoices->flatMap->payments->where('payment_status', 'accepted');
                        $paidAmount = (int)$acceptedPayments->sum('payment_amount');
                    }

                    $isLunas = ($submission->payment_status === 'paid')
                        || ($paidPercent >= 100)
                        || ($subFee > 0 && $paidAmount >= $subFee);

                    if ($isLunas) {
                        continue;
                    }

                    $isBelumLunas = ($paidPercent > 0 || $paidAmount > 0);
                    $isBelumBayar = ($paidPercent == 0 && $paidAmount == 0);

                    $isMatch = ($type === 'belum_lunas' && $isBelumLunas) || ($type === 'belum_bayar' && $isBelumBayar);
                    if (!$isMatch) {
                        continue;
                    }

                    $remaining = max(0, $subFee - $paidAmount);
                    $totalFee += $subFee;
                    $totalPaid += $paidAmount;
                    $totalRemaining += $remaining;

                    // Title format
                    $titleRaw = $submission->fullTitle;
                    $title = is_array($titleRaw)
                        ? implode(', ', $titleRaw)
                        : ($titleRaw ?: ($submission->title ?? 'Untitled'));

                    // Authors format
                    $authors = $submission->authorsString;
                    if (empty($authors) && is_array($submission->authors)) {
                        $authors = collect($submission->authors)->pluck('name')->filter()->implode(', ');
                    }

                    // Published status check
                    $isPublished = ($submission->status == 3)
                        || !empty($submission->urlPublished)
                        || Str::contains(strtolower($submission->status_label ?? ''), 'publish');

                    // Invoices detail
                    $invoices = $submission->paymentInvoices->map(function ($inv) {
                        return [
                            'invoice_number' => $inv->invoice_number ?: '-',
                            'payment_percent' => (int)$inv->payment_percent,
                            'is_paid' => (bool)$inv->is_paid,
                            'due_date' => $inv->payment_due_date ? \Carbon\Carbon::parse($inv->payment_due_date)->format('d/m/Y') : null,
                        ];
                    })->values();

                    $matchedSubmissions[] = [
                        'submission_id' => (string)$submission->submission_id,
                        'title' => $title,
                        'authors' => $authors ?: '-',
                        'issue_label' => $issueLabel,
                        'is_published' => $isPublished,
                        'status_label' => $submission->status_label ?: ($isPublished ? 'Published' : 'Belum Publish'),
                        'author_fee' => $subFee,
                        'paid_amount' => $paidAmount,
                        'paid_percent' => $subFee > 0 ? (int)round(($paidAmount / $subFee) * 100) : $paidPercent,
                        'remaining_amount' => $remaining,
                        'invoices' => $invoices,
                        'action_url' => route('back.journal.article.index', [$journal->url_path, $issue->id]),
                    ];
                }
            }

            $issueMeta = null;
            if ($selectedIssue) {
                $issueMeta = [
                    'id' => $selectedIssue->id,
                    'label' => 'Vol. ' . $selectedIssue->volume . ' No. ' . $selectedIssue->number . ($selectedIssue->year ? ' (' . $selectedIssue->year . ')' : ''),
                ];
            }

            return response()->json([
                'success' => true,
                'meta' => [
                    'journal' => [
                        'id' => $journal->id,
                        'name' => $journal->name,
                    ],
                    'issue' => $issueMeta,
                    'total_count' => count($matchedSubmissions),
                    'total_fee' => $totalFee,
                    'total_paid' => $totalPaid,
                    'total_remaining' => $totalRemaining,
                ],
                'submissions' => $matchedSubmissions,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Gagal memuat daftar submission',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
