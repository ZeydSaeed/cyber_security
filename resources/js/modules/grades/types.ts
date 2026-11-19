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
    state: 'edit' | 'timed' | 'view_only' | 'closed' | 'granted' | 'published';
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

/** One student's line in a results sheet (ResultSheets). */
export type ResultRow = {
    uuid: string;
    name: string;
    number: string | null;
    marks: MarkValue[];
    total: number;
    maximum: number;
    percentage: number;
    below: number;
    absences: number;
    missing: number;
    complete: boolean;
    passed: boolean;
};

export type ResultSheet = {
    stage: number;
    section: string;
    divided: boolean;
    pass_mark: number;
    subjects: { uuid: string; name: string }[];
    students: ResultRow[];
    summary: { students: number; complete: number; passed_all: number };
};
