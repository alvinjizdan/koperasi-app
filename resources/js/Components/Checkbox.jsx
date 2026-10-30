export default function Checkbox({ className = '', ...props }) {
    return (
        <input
            {...props}
            type="checkbox"
            className={
                'rounded border-slate-300 text-bsm-primary shadow-2xs focus:ring-bsm-primary ' +
                className
            }
        />
    );
}
