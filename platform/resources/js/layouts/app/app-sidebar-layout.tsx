import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { BrandStyle } from '@/components/brand-style';
import { ImpersonationBanner } from '@/components/impersonation-banner';
import { AlertToasts } from '@/components/alert-toasts';
import { type BreadcrumbItem } from '@/types';

export default function AppSidebarLayout({ children, breadcrumbs = [] }: { children: React.ReactNode; breadcrumbs?: BreadcrumbItem[] }) {
    return (
        <AppShell variant="sidebar">
            <BrandStyle />
            <AppSidebar />
            <AppContent variant="sidebar">
                <ImpersonationBanner />
                <AlertToasts />
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
        </AppShell>
    );
}
