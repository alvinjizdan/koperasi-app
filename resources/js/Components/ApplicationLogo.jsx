export default function ApplicationLogo({ className = 'h-10 w-10', alt = 'BSM Logo', ...props }) {
    return (
        <img
            src="/images/logo.png"
            alt={alt}
            className={`object-contain ${className}`}
            {...props}
        />
    );
}
