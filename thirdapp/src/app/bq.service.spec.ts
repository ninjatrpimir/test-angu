import { TestBed } from '@angular/core/testing';

import { BqService } from './bq.service';

describe('BqService', () => {
  beforeEach(() => TestBed.configureTestingModule({}));

  it('should be created', () => {
    const service: BqService = TestBed.get(BqService);
    expect(service).toBeTruthy();
  });
});
