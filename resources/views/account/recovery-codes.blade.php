<x-app>
    <x-validation-errors class="mb-4" />
    <x-validation-status class="mb-4" />
    <x-card>
        <x-slot name="title">
            {{__('Multi-factor Recovery Codes')}}
        </x-slot>
        
        <x-slot name="content">
            <h1 class="font-bold text-lg">Recovery codes can be used to access your account in the event you lose access to your device and cannot receive multi-factor authentication codes.</h1>
            @if (count($user->recoveryCodes) > 0)
                <div class="border rounded-lg">
                    <div class="p-4 font-bold">Recovery Codes</div>
                    <div class="p-4 bg-blue-100 text-sm flex items-start gap-2 text-blue-900 rounded">
                        <div class="text-blue-600">
                            <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 16 16">
                                <path d="M6.457 1.047c.659-1.234 2.427-1.234 3.086 0l6.082 11.378A1.75 1.75 0 0 1 14.082 15H1.918a1.75 1.75 0 0 1-1.543-2.575Zm1.763.707a.25.25 0 0 0-.44 0L1.698 13.132a.25.25 0 0 0 .22.368h12.164a.25.25 0 0 0 .22-.368Zm.53 3.996v2.5a.75.75 0 0 1-1.5 0v-2.5a.75.75 0 0 1 1.5 0ZM9 11a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z" />
                            </svg>
                        </div>
                        <div>
                            <strong>Keep your recovery codes in a safe spot.</strong> These codes are the last resort for accessing your account in case you lose your password and second factors. If you cannot find these codes, you will lose access to your account.
                        </div>
                    </div>
                    <div x-data="recoveryCodesHandler('{{ Str::lower(env('APP_NAME')) }}')" class="w-3/4 flex flex-col justify-self-center text-center p-4">
                        <ul id="recovery-codes" class="flex flex-wrap gap-2 text-start list-disc">
                            @foreach ($user->recoveryCodes as $code)
                                <li class="font-semibold tracking-widest" style="width: 24%">{{ $code->code }}</li>
                            @endforeach
                        </ul>
                        <div class="flex gap-4 justify-center mt-6">
                            <x-secondary-button @click="downloadCodes" class="hover:underline">Download</x-secondary-button>
                            <x-secondary-button @click="printCodes" class="hover:underline">Print</x-secondary-button>
                            <x-secondary-button @click="copyCodes" class="hover:underline">Copy</x-secondary-button>
                        </div>
                        <!-- Hidden Printable Section -->
                        <div id="printable-area" class="hidden print:block">
                            <div 
                                class="flex flex-col justify-self-center border border-4 border-black text-start w-3/4 p-6 mt-6 mx-auto"
                                style="font-family: monospace; font-size: 16px;"
                            >
                                <h1 class="text-2xl font-bold mb-4">Recovery Codes</h1>
                                <ul class="list-disc flex flex-col gap-2 px-10">
                                    @foreach ($user->recoveryCodes as $code)
                                        <li class="tracking-widest">{{ $code->code }}</li>
                                    @endforeach
                                </ul>
                                <p class="text-lg mt-2">{{env('APP_NAME')}} multi-factor authentication account recovery codes</p>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
            <div class="flex flex-col gap-4">
                <h1 class="font-bold text-lg">Generate new recovery codes</h1>
                <p>When you generate new recovery codes, you must download or print the new codes. Your old codes won't work anymore.</p>
                <form method="POST" action="{{route('recovery.generate')}}">
                    @csrf
                    <x-secondary-button type="submit">{{ __('Generate new recovery codes') }}</x-secondary-button>
                </form>
            </div>
        </x-slot>
    </x-card>
</x-app>
<script>
    function recoveryCodesHandler(appName) {
        return {
            getCodes() {
                return Array.from(document.querySelectorAll('#recovery-codes li'))
                    .map(el => el.textContent.trim())
                    .join('\n');
            },
            downloadCodes() {
                const codes = this.getCodes();
                const blob = new Blob([codes], { type: 'text/plain' });
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = `${appName}-recovery-codes.txt`;
                link.click();
            },
            printCodes() {
                const originalContent = document.body.innerHTML;
                const printable = document.getElementById('printable-area').innerHTML;

                document.body.innerHTML = printable;
                window.print();
                document.body.innerHTML = originalContent;
                window.location.reload();
            },
            copyCodes() {
                navigator.clipboard.writeText(this.getCodes())
                    .then(() => console.log('Copied'))
                    .catch(() => console.error('Failed to copy'));
            }
        }
    }
</script>