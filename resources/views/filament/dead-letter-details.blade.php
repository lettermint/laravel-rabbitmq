<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <div>
            <dt class="font-medium">Queue</dt>
            <dd>{{ $this->queue }}</dd>
        </div>
        <div>
            <dt class="font-medium">Job ID</dt>
            <dd class="break-all">{{ $record['id'] }}</dd>
        </div>
        <div>
            <dt class="font-medium">Job</dt>
            <dd class="break-all">{{ $record['job_class'] }}</dd>
        </div>
        <div>
            <dt class="font-medium">Reason</dt>
            <dd>{{ $record['reason'] }}</dd>
        </div>
    </dl>

    @if (filled($record['exception']))
        <div>
            <h3 class="font-medium">Exception</h3>
            <pre class="mt-1 max-h-64 overflow-auto whitespace-pre-wrap rounded bg-gray-950 p-3 text-xs text-white">{{ $record['exception'] }}</pre>
        </div>
    @endif

    <div>
        <h3 class="font-medium">Payload</h3>
        <pre class="mt-1 max-h-96 overflow-auto whitespace-pre-wrap rounded bg-gray-950 p-3 text-xs text-white">{{ json_encode($record['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
</div>
