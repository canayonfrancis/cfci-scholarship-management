@props(['paginator'])
@if($paginator instanceof \App\Support\TablePaginator)
    @php
        $tableState = [...$paginator->tableSettings, 'pageName' => $paginator->getPageName(), 'page' => $paginator->currentPage(), 'total' => $paginator->total(), 'lastPage' => $paginator->lastPage(), 'first' => $paginator->firstItem(), 'last' => $paginator->lastItem()];
    @endphp
    <script type="application/json" class="data-table-state">@json($tableState)</script>
@endif
