/** A mark as the server sends it: a number, "absent", or none yet. */
export type MarkValue = number | 'absent' | null;

export type TeacherSubject = {
    uuid: string;
    name: string;
    stage: number;
    divided: boolean;
    sections: { section: string; students: number; entered: number }[];
};

export type SheetStudent = {
    uuid: string;
    name: string;
    number: string | null;
    mark: MarkValue;
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
