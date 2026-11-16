import AppLogoIcon from './app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="text-brand flex aspect-square size-8 items-center justify-center">
                <AppLogoIcon className="size-7" />
            </div>
            <span className="font-display ml-1 truncate text-xl leading-none font-semibold tracking-tight">seguria</span>
        </>
    );
}
