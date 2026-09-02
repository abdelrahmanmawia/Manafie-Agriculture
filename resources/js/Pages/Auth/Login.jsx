import { useEffect, useState } from 'react';
import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import ApplicationLogo from '@/Components/ApplicationLogo';
import { Head, Link, useForm } from '@inertiajs/react';
import { t } from '@/Helpers/i18n';

export default function Login({ status, canResetPassword }) {
    const [showPassword, setShowPassword] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    useEffect(() => {
        return () => {
            reset('password');
        };
    }, []);

    const submit = (e) => {
        e.preventDefault();

        post(route('login'));
    };

    return (
        <>
            <Head title={t('login')} />

            <div className="min-h-screen w-full flex bg-white">
                {/* Brand panel — hidden below lg, this is a desktop-first split-screen layout */}
                <div className="hidden lg:flex lg:w-[45%] relative overflow-hidden bg-gray-900 items-center justify-center p-16">
                    {/* The real brand mark, oversized and faint, as texture rather than a stock icon */}
                    <ApplicationLogo className="absolute -right-24 -bottom-24 h-[520px] w-[520px] text-white opacity-[0.06]" />
                    <ApplicationLogo className="absolute -left-32 -top-32 h-[360px] w-[360px] text-white opacity-[0.04] rotate-12" />

                    <div className="relative z-10 max-w-sm">
                        <ApplicationLogo className="h-12 w-12 text-white mb-10" />
                        <h1 className="text-4xl font-black text-white uppercase tracking-tighter leading-[1.1]">
                            Gestion Agricole
                        </h1>
                        <p className="mt-4 text-sm font-bold text-gray-400 uppercase tracking-widest leading-relaxed">
                            Pointage · Paie · Stock — en un seul endroit
                        </p>
                    </div>
                </div>

                {/* Form panel */}
                <div className="flex-1 flex items-center justify-center px-6 py-12 sm:px-12">
                    <div className="w-full max-w-sm">
                        <div className="flex items-center gap-3 mb-10 lg:hidden">
                            <ApplicationLogo className="h-9 w-9 text-gray-900" />
                            <span className="font-black text-lg text-gray-900 uppercase tracking-tighter">Gestion Agricole</span>
                        </div>

                        <h2 className="text-2xl font-black text-gray-900 uppercase tracking-tighter">Connexion</h2>
                        <p className="mt-1.5 text-sm text-gray-500 font-medium">Accédez à votre espace de gestion.</p>

                        {status && (
                            <div className="mt-6 bg-success-50 border border-success-200 text-success-700 px-4 py-3 rounded-xl text-sm font-bold">
                                {status}
                            </div>
                        )}

                        <form onSubmit={submit} className="mt-8 space-y-5">
                            <div>
                                <InputLabel htmlFor="email" value={t('email')} className="text-[10px] font-black uppercase text-gray-400 tracking-widest mb-1.5" />
                                <TextInput
                                    id="email"
                                    type="email"
                                    name="email"
                                    value={data.email}
                                    className="block w-full rounded-xl border-gray-200 bg-gray-50 text-sm font-bold text-gray-900 focus:border-primary-500 focus:bg-white focus:ring-primary-500 py-3 px-4 transition-all"
                                    autoComplete="username"
                                    isFocused={true}
                                    onChange={(e) => setData('email', e.target.value)}
                                />
                                <InputError message={errors.email} className="mt-1.5" />
                            </div>

                            <div>
                                <InputLabel htmlFor="password" value={t('password')} className="text-[10px] font-black uppercase text-gray-400 tracking-widest mb-1.5" />
                                <div className="relative">
                                    <TextInput
                                        id="password"
                                        type={showPassword ? 'text' : 'password'}
                                        name="password"
                                        value={data.password}
                                        className="block w-full rounded-xl border-gray-200 bg-gray-50 text-sm font-bold text-gray-900 focus:border-primary-500 focus:bg-white focus:ring-primary-500 py-3 pl-4 pr-11 transition-all"
                                        autoComplete="current-password"
                                        onChange={(e) => setData('password', e.target.value)}
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword((v) => !v)}
                                        className="absolute inset-y-0 right-0 flex items-center px-3.5 text-gray-400 hover:text-gray-600 transition-colors"
                                        aria-label={showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
                                    >
                                        {showPassword ? (
                                            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21m-6.122-6.122l3.244 3.244" />
                                            </svg>
                                        ) : (
                                            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            </svg>
                                        )}
                                    </button>
                                </div>
                                <InputError message={errors.password} className="mt-1.5" />
                            </div>

                            <div className="flex items-center justify-between pt-1">
                                <label htmlFor="remember" className="flex items-center gap-2 cursor-pointer select-none">
                                    <Checkbox
                                        name="remember"
                                        checked={data.remember}
                                        onChange={(e) => setData('remember', e.target.checked)}
                                    />
                                    <span className="text-xs font-bold text-gray-600">{t('remember_me')}</span>
                                </label>

                                {canResetPassword && (
                                    <Link
                                        href={route('password.request')}
                                        className="text-xs font-bold text-primary-600 hover:text-primary-800 transition-colors"
                                    >
                                        {t('forgot_password')}
                                    </Link>
                                )}
                            </div>

                            <PrimaryButton className="w-full justify-center py-3.5 mt-2" disabled={processing}>
                                {processing ? (
                                    <span className="flex items-center justify-center gap-2">
                                        <svg className="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Connexion...
                                    </span>
                                ) : (
                                    t('login')
                                )}
                            </PrimaryButton>
                        </form>
                    </div>
                </div>
            </div>
        </>
    );
}
