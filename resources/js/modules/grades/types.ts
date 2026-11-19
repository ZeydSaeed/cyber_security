/** A mark as the server sends it: a number, "absent", or none yet. */
export type MarkValue = number | 'absent' | null;

export type TeacherSubject = {
    uuid: string;
    name: string;
    stage: number;
    divided: boolean;
    sections: {
        section: string;
        students: number;
        entered: number;
        access: SectionAccess;
    }[];
};

/**
 * What a teacher may do in one section (ADR-0037), as TeacherSheets
 * describes it: the state decides the notice, can_write the sheet.
 */
export type SectionAccess = {
    state:
        | 'edit'
        | 'timed'
        | 'view_only'
        | 'closed'
        | 'granted'
        | 'approved'
        | 'published';
    can_write: boolean;
    /** Timed mode: hours a mark stays editable after it is entered. */
    hours: number | null;
    /** Granted: until when. */
    until: string | null;
};

/** A teacher's latest request to change a section's marks again. */
export type EditRequest = {
    status: 'pending' | 'approved' | 'rejected';
    reason: string;
    granted_hours: number | null;
    created_at: string;
    decided_at: string | null;
};

/** The access modes the owner can set (Domain\TeacherAccess). */
export type TeacherMode = 'edit' | 'timed' | 'view_only' | 'blocked';

export type SheetStudent = {
    uuid: string;
    name: string;
    number: string | null;
    mark: MarkValue;
    /** Past its editing time (timed mode): shown, not changeable. */
    locked?: boolean;
};

/** Who is signed in on a teacher's or student's page, and how to leave. */
export type PortalProps = { portal?: { name: string; logout: string } };

/** A stage and its sections, as the server describes them (Stage::sections). */
export type StageLayout = {
    stage: number;
    sections: string[];
    divided: boolean;
};

/** A grade band (Domain\GradeBand). */
export type Band =
    | 'excellent'
    | 'very_good'
    | 'good'
    | 'fair'
    | 'pass'
    | 'fail';

/** What one entered mark earns (ADR-0039); null: not entered. */
export type ResultCell = {
    percent: number;
    passed: boolean;
    /** Null on a student's card when the owner hides mark grades. */
    band: Band | null;
    exempt: boolean;
} | null;

/** A subject of a results sheet, with its scale and the section's teacher. */
export type ResultSubject = {
    uuid: string;
    name: string;
    max: number;
    pass: number;
    exempt: number | null;
    teacher: string | null;
    assistant: string | null;
    kind: 'theory' | 'practical' | null;
};

/** What a result card shows (Domain\ResultDisplay::OPTIONS). */
export type ResultOptions = Record<
    | 'total'
    | 'percentage'
    | 'rank'
    | 'failed_count'
    | 'mark_grade'
    | 'overall_grade'
    | 'exemption'
    | 'max_mark'
    | 'teachers'
    | 'kind'
    | 'student_status'
    | 'behaviour'
    | 'notes',
    boolean
>;

export type Tone = 'none' | 'success' | 'warning' | 'info' | 'danger';

/** The tones of marks (Domain\ResultDisplay::TONED). */
export type MarkTones = Record<'fail' | 'fair' | 'exempt' | 'excellent', Tone>;

/**
 * One student's line in a results sheet (ResultSheets). On a student's own
 * card, what the owner hides comes as null.
 */
export type ResultRow = {
    uuid: string;
    name: string;
    number: string | null;
    status: string | null;
    marks: MarkValue[];
    cells: ResultCell[];
    total: number | null;
    maximum: number | null;
    percentage: number | null;
    below: number | null;
    absences: number;
    missing: number;
    complete: boolean;
    passed: boolean;
    rank: number | null;
    band: Band | null;
    exempt_all: boolean | null;
    behaviour: string | null;
    notes: string | null;
};

/** What every sheet and card carries besides its students. */
export type ResultFrame = {
    stage: number;
    section: string;
    divided: boolean;
    pass_percent: number;
    exempt_overall: number | null;
    options: ResultOptions;
    tones: MarkTones;
    subjects: ResultSubject[];
};

/** One subject's figures for a section (ADR-0041), aligned with subjects. */
export type SubjectStats = {
    entered: number;
    absent: number;
    passed: number;
    pass_rate: number | null;
    highest: number | null;
    lowest: number | null;
    mean: number | null;
};

export type ResultSheet = ResultFrame & {
    students: ResultRow[];
    stats: SubjectStats[];
    summary: { students: number; complete: number; passed_all: number };
};

/** One student's card (ResultSheets::student / forStudent). */
export type ResultCardData = ResultFrame & { student: ResultRow };
