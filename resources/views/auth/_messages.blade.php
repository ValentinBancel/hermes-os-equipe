@if (session('status'))
    <p class="flash ok" role="status">{{ session('status') }}</p>
@endif

@if ($errors->any())
    <div id="erreurs" role="alert" class="flash erreur">
        <span>@foreach ($errors->all() as $message){{ $message }}@if (! $loop->last)<br>@endif @endforeach</span>
    </div>
@endif
