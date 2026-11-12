<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>CFCI — {{ $result['title'] }}</title><style>body{font:13px/1.5 Arial,sans-serif;color:#111;margin:24px}main{max-width:1500px;margin:auto}table{border-collapse:collapse;width:100%;margin:18px 0}th,td{border:1px solid #aaa;text-align:left;padding:7px;vertical-align:top;overflow-wrap:anywhere}.records-details{display:flex;flex-wrap:wrap;gap:20px}dd{margin:4px 0}section{margin:20px 0}.records-table-wrap{overflow-x:auto}@media print{button{display:none}body{margin:0;font-size:10px}tr{break-inside:avoid}.records-table-wrap{overflow:visible}a{color:#111;text-decoration:none}}</style></head>
<body><main><h1>Cronasia Foundation College, Inc.</h1><h2>{{ $result['title'] }}</h2><button type="button" onclick="window.print()">Print report</button>
@include('scholarship-reports.summary')
@include('scholarship-reports.table', ['reportRows' => $result['rows']])
@include('scholarship-reports.context')
</main></body></html>
