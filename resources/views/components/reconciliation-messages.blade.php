@if (session('success'))
    <p role="status" class="mb-4 rounded bg-green-100 p-3 text-green-900">{{ session('success') }}</p>
@endif
@if ($errors->any())
    <div role="alert" class="mb-4 rounded bg-red-100 p-3 text-red-900">
        @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
    </div>
@endif
