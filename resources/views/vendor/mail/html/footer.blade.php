<tr>
<td>
<table class="footer" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center">
{{ Illuminate\Mail\Markdown::parse($slot) }}
<p style="font-size: 12px; color: #94a3b8; margin: 6px 0 0;">
{{ __('This is an automated message. Please do not reply directly to this email.') }}
</p>
@if (\Illuminate\Support\Facades\Route::has('contact-us'))
<p style="font-size: 12px; color: #94a3b8; margin: 4px 0 0;">
<a href="{{ url('/contact-us') }}" style="color: #64748b; text-decoration: underline;">{{ __('Contact us') }}</a>
</p>
@endif
</td>
</tr>
</table>
</td>
</tr>
