/** A mark as the server sends it: a number, "absent", or none yet. */
export type MarkValue = number | 'absent' | null;

export type TeacherSubject = {
    uuid: string;
    name: string;
    stage: number;
    sections: { section: string; students: number; entered: number }[];
};

export type SheetStudent = {
    uuid: string;
    name: string;
    number: string | null;
    mark: MarkValue;
};

export type TeacherProps = { teacher: { name: string } };

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
    pass_mark: number;
    subjects: { uuid: string; name: string }[];
    students: ResultRow[];
    summary: { students: number; complete: number; passed_all: number };
};
