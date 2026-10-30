import {
    forwardRef,
    useEffect,
    useImperativeHandle,
    useRef,
    useState,
} from "react";
import { Eye, EyeOff } from "lucide-react";

/**
 * Input password dengan tombol toggle lihat/sembunyikan password.
 * API sama dengan TextInput (kompatibel dengan pemakaian existing).
 */
export default forwardRef(function PasswordInput(
    { type = "password", className = "", isFocused = false, ...props },
    ref,
) {
    const localRef = useRef(null);
    const [visible, setVisible] = useState(false);

    useImperativeHandle(ref, () => ({
        focus: () => localRef.current?.focus(),
    }));

    useEffect(() => {
        if (isFocused) {
            localRef.current?.focus();
        }
    }, [isFocused]);

    // `type` prop diabaikan secara sengaja: komponen ini selalu mengelola
    // visibilitasnya sendiri (password <-> text) melalui tombol toggle.
    return (
        <div className="relative">
            <input
                {...props}
                type={visible ? "text" : "password"}
                className={
                    "rounded-lg border-slate-300 shadow-2xs focus:border-bsm-primary focus:ring-1 focus:ring-bsm-primary pe-10 " +
                    className
                }
                ref={localRef}
            />
            <button
                type="button"
                tabIndex={-1}
                onClick={() => setVisible((v) => !v)}
                aria-label={visible ? "Sembunyikan password" : "Lihat password"}
                className="absolute inset-y-0 end-0 flex items-center px-3 text-gray-400 hover:text-gray-600 focus:outline-none"
            >
                {visible ? <EyeOff size={18} /> : <Eye size={18} />}
            </button>
        </div>
    );
});
