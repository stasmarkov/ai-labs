export interface SceneNode {
    id: string;
    kind: string;
    label: string;
    x: number;
    y: number;
}

export interface SceneEdge {
    id: string;
    from: string;
    to: string;
}

export interface NodeState {
    load: number;
    queue: number;
    status: string;
    badge: string | null;
}

export interface EdgeState {
    visible: boolean;
    packets: Record<string, number>;
}

export interface Kpi {
    id: string;
    label: string;
    unit: string;
    format: string;
    better: string;
}

export interface KpiValue {
    id: string;
    label: string;
    value: string;
    trend: string;
}

export interface Change {
    sign: string;
    text: string;
}

export interface Callout {
    kind: string;
    text: string;
}

export interface Step {
    number: number;
    role: string;
    title: string;
    body: string[];
    callout: Callout | null;
    nodes: Record<string, NodeState>;
    edges: Record<string, EdgeState>;
    kpis: KpiValue[];
    changed: Change[];
    learn: string[];
    deeper: string | null;
}

export interface Lesson {
    module: string;
    slug: string;
    title: string;
    summary: string;
    minutes: number;
    level: string;
    learn: { id: string; text: string }[];
    guide: string | null;
    nodes: SceneNode[];
    edges: SceneEdge[];
    legend: string[];
    kpis: Kpi[];
    steps: Step[];
}

export interface CourseEntry {
    slug: string;
    title: string;
    summary: string;
    level: string;
    comingSoon: boolean;
    available: boolean;
    minutes: number | null;
    steps: number | null;
    href: string | null;
}

export interface CourseModule {
    slug: string;
    title: string;
    summary: string;
    lessons: CourseEntry[];
}

export interface Course {
    modules: CourseModule[];
}
