export default function PrimaryButton({
    className = '',
    disabled,
    children,
    ...props
}) {
    return (
        <button
            {...props}
            className={
                `inline-flex items-center rounded-lg border border-transparent bg-bsm-primary px-4 py-2 text-xs font-semibold uppercase tracking-wider text-white shadow-xs transition duration-150 ease-in-out hover:bg-bsm-primary-hover focus:bg-bsm-primary-hover focus:outline-none focus:ring-2 focus:ring-bsm-accent focus:ring-offset-2 active:bg-bsm-primary-hover ${
                    disabled && 'opacity-25'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
