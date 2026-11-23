{{--
    The validation message for one field, in the household's words rather than
    Laravel's (the wording comes from App\Livewire\Settings::messages()).

    `id` is what the control points at with aria-describedby, so a screen reader
    reads the problem with the field rather than leaving it unexplained.
--}}
@props(['field', 'id' => null])

@error($field)
    <p @if ($id) id="{{ $id }}" @endif class="mt-1 text-sm font-medium text-red-700">
        {{ $message }}
    </p>
@enderror
