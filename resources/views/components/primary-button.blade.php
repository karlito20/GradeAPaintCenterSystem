<button
    {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-[#00a3cc] border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-[#008fb3] focus:bg-[#008fb3] active:bg-[#007a99] focus:outline-none focus:ring-2 focus:ring-[#00a3cc] focus:ring-offset-2 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
