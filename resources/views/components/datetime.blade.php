@props(['value', 'format' => 'Y-m-d H:i', 'timezone' => 'Asia/Tokyo'])

@if($value){{ \Illuminate\Support\Carbon::parse($value)->setTimezone($timezone)->format($format) }}@endif
