import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react';
import PasswordVisibilityToggle from './PasswordVisibilityToggle';

export default forwardRef(function TextInput(
    { type = 'text', className = '', isFocused = false, ...props },
    ref,
) {
    const localRef = useRef(null);
    const [passwordVisible, setPasswordVisible] = useState(false);

    useImperativeHandle(ref, () => ({
        focus: () => localRef.current?.focus(),
    }));

    useEffect(() => {
        if (isFocused) {
            localRef.current?.focus();
        }
    }, [isFocused]);

    const input = (
        <input
            {...props}
            type={type === 'password' && passwordVisible ? 'text' : type}
            className={
                'rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 ' +
                className +
                (type === 'password' ? ' pr-16' : '')
            }
            ref={localRef}
        />
    );

    if (type !== 'password') {
        return input;
    }

    return (
        <span className="relative block">
            {input}
            <PasswordVisibilityToggle
                visible={passwordVisible}
                onClick={() => setPasswordVisible((visible) => !visible)}
                className="focus-visible:ring-indigo-500"
            />
        </span>
    );
});
